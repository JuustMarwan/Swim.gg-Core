<?php

namespace core\scenes\duel;

use core\scenes\PvP;
use core\SwimCore;
use core\systems\map\MapInfo;
use core\systems\player\components\ClickHandler;
use core\systems\player\SwimPlayer;
use core\systems\scene\misc\Team;
use core\utils\AcData;
use core\utils\CoolAnimations;
use core\utils\PositionHelper;
use core\utils\ServerSounds;
use core\utils\TimeHelper;
use jackmd\scorefactory\ScoreFactory;
use jackmd\scorefactory\ScoreFactoryException;
use JetBrains\PhpStorm\ArrayShape;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\player\GameMode;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

abstract class Duel extends PvP
{

  // only nodebuff boxing and midfight are included in swimcore public
  public static array $MODES = ['nodebuff', 'boxing', 'midfight', 'bedfight', 'fireball fight', 'bridge', 'battlerush', 'skywars', 'buhc'];

  private int $secondsFinished = 0;
  protected int $seconds;
  protected int $duelCountDownTime;
  protected float $warpUnits;

  protected MapInfo $map; // this will be of a derived type for duels like Bed fight and bridge

  protected bool $started;
  protected bool $finished;
  protected bool $spawnCloser;
  protected bool $isPartyDuel;
  protected bool $lockMovement;
  protected bool $messageCountDown;
  protected bool $updateScoreTagEachSecond;
  protected bool $updateScoreBoardEachSecond;

  /**
   * @var SwimPlayer[]
   */
  protected array $losers; // everyone in the scene who got final killed

  public function __construct(SwimCore $core, string $name, World $world)
  {
    $this->world = $world;
    parent::__construct($core, $name);
    $this->seconds = 0;
    $this->duelCountDownTime = 5;
    $this->warpUnits = 25;
    $this->started = false;
    $this->finished = false;
    $this->isDuel = true;
    $this->lockMovement = false;
    $this->spawnCloser = false; // for games like mid-fight this will be true, should never be enabled when more than 2 teams
    $this->isPartyDuel = false; // will be set by party system later on creating a duel
    $this->messageCountDown = true;
    $this->updateScoreTagEachSecond = true;
    $this->updateScoreBoardEachSecond = true;
    $this->losers = [];

    // set up the spec team
    $specTeam = $this->teamManager->makeTeam("spectators", TextFormat::RESET);
    $specTeam->setSpecTeam(true);
    $this->teamManager->setSpecTeam($specTeam);
  }

  // duel must provide a path for the icon
  abstract public static function getIcon(): string;

  public function isFinished(): bool
  {
    return $this->finished;
  }

  public function getNonSpecsPlayerCount(): int
  {
    $count = 0;
    foreach ($this->teamManager->getTeams() as $team) {
      if (!$team->isSpecTeam()) {
        $count += count($team->getPlayers());
      }
    }

    return $count;
  }

  public final function setMap(MapInfo $mapInfo): void
  {
    $this->map = $mapInfo;
  }

  public final function getMap(): MapInfo
  {
    return $this->map;
  }

  // add in player team positions before doing this!
  public final function warpPlayersIn(): void
  {
    $teams = $this->teamManager->getTeams();
    foreach ($teams as $team) {
      $spawnPoints = $team->getSpawnPoints();
      if (empty($spawnPoints)) {
        continue; // Skip if there are no spawn points
      }

      // Will use this later for moving to next spawn point while iterating the team players
      $count = count($spawnPoints);
      $teamCount = count($teams);

      $spawnIndex = 0;
      $players = $team->getPlayers();
      foreach ($players as $player) {
        $spawnPos = $spawnPoints[$spawnIndex];

        // Check if players need to be spawned closer to another team's spawn point
        if ($this->spawnCloser && $teamCount > 1) {
          // Find the first spawn point of the first other team which will be used to move closer to
          foreach ($teams as $otherTeam) {
            $otherSpawns = $otherTeam->getSpawnPoints();
            if ($otherTeam !== $team && !empty($otherSpawns)) {
              $pos0 = $otherSpawns[0]; // first spawn
              // Calculate new position to move closer by warp units
              $spawnPos = PositionHelper::moveCloserTo($spawnPos, $pos0, $this->warpUnits);
              break; // only need to do this once
            }
          }
        }

        // Teleport player to either the original or modified position
        $player->teleport($spawnPos);

        if ($this->lockMovement) {
          $player->setNoClientPredictions(); // Prevent movement
        }

        // Move to the next spawn point or loop back
        $spawnIndex = ($spawnIndex + 1) % $count;
      }
    }

    $this->teamManager->lookAtEachOther();
  }

  public function sceneEntityDamageByEntityEvent(EntityDamageByEntityEvent $event, SwimPlayer $swimPlayer): void
  {
    $attacker = $event->getDamager();
    if ($attacker instanceof SwimPlayer) {

      // can't attack teammates
      if ($this->arePlayersInSameTeam($swimPlayer, $attacker)) {
        $event->cancel();
        return;
      }

      // fix spectator hits exploit where they hit you the same tick they change game mode back to survival from spectator,
      // due to the teleport not registering on the client yet
      if ($attacker->getTicksSinceLastGameModeChange() < 10) {
        $event->cancel();
        if (SwimCore::$DEBUG) {
          echo("Spectator exploit patched!\n");
        }
        return;
      }

      $vKB = $this->vertKB;
      $kb = $this->kb;

      // different KB per input mode
      $inputMode = $swimPlayer->getAntiCheatData()?->getData(AcData::INPUT_MODE);
      $controller = $inputMode == InputMode::GAME_PAD || $inputMode == InputMode::MOTION_CONTROLLER;
      if ($controller) {
        $vKB = $this->controllerVertKB;
        $kb = $this->controllerKB;
      }

      // minimize the KB dealt if attacker cps is above the max value
      if ($this->ranked && ($attacker->getClickHandler()->getCPS() > ClickHandler::CPS_MAX)) {
        $vKB /= 1.1;
        $kb /= 1.1;
      }

      // KB logic
      $event->setVerticalKnockBackLimit($vKB);
      $event->setKnockBack($kb);
      $event->setAttackCooldown($this->hitCoolDown);

      // call back for hitting another player (This is maybe not good because we might want a way to have different callbacks for when hit by a projectile)
      $this->playerHit($attacker, $swimPlayer, $event);

      // update who last hit them
      // $swimPlayer->getCombatLogger()->setlastHitBy($attacker);
      $attacker->getCombatLogger()->handleAttack($swimPlayer); // this seems a lot better

      // Death logic
      if ($event->getFinalDamage() >= $swimPlayer->getHealth()) {
        $event->cancel(); // cancel event, so we don't vanilla kill them
        // call back the functions the derived scene must implement
        $this->playerKilled($attacker, $swimPlayer, $event);
      }
    } elseif ($event->getFinalDamage() >= $swimPlayer->getHealth()) { // for when killed by AI server sided entities like monsters or falling blocks
      $event->cancel(); // cancel event, so we don't vanilla kill them
      // call back the function the derived scene must implement
      $this->playerDiedToMiscDamage($event, $swimPlayer);
    }
  }

  // attempts to get the last hit by via combat logger
  protected function playerDiedToMiscDamage(EntityDamageEvent $event, SwimPlayer $swimPlayer): void
  {
    $lastHitBy = $swimPlayer->getCombatLogger()->getLastHitBy();
    if (isset($lastHitBy)) {
      $this->defaultDeathHandle($lastHitBy, $swimPlayer);
    } else {
      // no hitter logged then have to just kill and eliminate instantly
      $this->playerFinalKilled($swimPlayer);
    }
  }

  protected function hitByProjectile(SwimPlayer $hitPlayer, SwimPlayer $hitter, Entity $projectile, EntityDamageByChildEntityEvent $event): void
  {
    parent::hitByProjectile($hitPlayer, $hitter, $projectile, $event);
    $hitPlayer->getCombatLogger()?->setLastHitBy($hitter);
  }

  // you should override this for games that have respawn enabled and handle accordingly
  protected function playerDiedToChildEntity(EntityDamageByChildEntityEvent $event, SwimPlayer $victim, SwimPlayer $attacker, Entity $childEntity): void
  {
    $this->defaultDeathHandle($attacker, $victim);
  }

  // called back automatically when a player is killed by another player
  // optional override
  protected function playerKilled(SwimPlayer $attacker, SwimPlayer $victim, EntityDamageByEntityEvent $event): void
  {
    $this->defaultDeathHandle($attacker, $victim);
  }

  protected function defaultDeathHandle(SwimPlayer $attacker, SwimPlayer $victim): void
  {
    if ($this->isPartyDuel) {
      $victimStr = $victim->getRank()->rankString();
      $attacker->sendMessage(TextFormat::GREEN . "You Killed " . $victimStr);
      // $this->sceneAnnouncement($attacker->getRank()->rankString() . TextFormat::YELLOW . " Killed " . $victimStr);
      $myTeam = $this->getPlayerTeam($attacker);
      $loserTeam = $this->getPlayerTeam($victim);
      if ($myTeam && $loserTeam) {
        $msg = $myTeam->getTeamColor() . $attacker->getNicks()->getNick() . TextFormat::YELLOW
          . " Killed " . $loserTeam->getTeamColor() . $victim->getNicks()->getNick();
        $this->sceneAnnouncement($msg);
      }
    }
    $this->playerFinalKilled($victim);
  }

  // used for only the handling of the victim, eliminating them from the game and putting in spectator
  // this calls player elimination after the death effect, then resets inventory and puts them in spectator
  protected final function playerFinalKilled(SwimPlayer $victim): void
  {
    $this->deathEffect($victim);
    $this->playerElimination($victim);
  }

  protected function deathEffect(SwimPlayer $swimPlayer, bool $blood = true, bool $explode = false, bool $bolt = false): void
  {
    // kill message cosmetic
    $attacker = $swimPlayer->getCombatLogger()->getLastHitBy();
    $attacker?->getCosmetics()?->killMessageLogic($swimPlayer);

    $pos = $swimPlayer->getPosition();
    if ($blood) CoolAnimations::bloodDeathAnimation($pos, $this->world);
    if ($explode) CoolAnimations::explodeAnimation($pos, $this->world);
    if ($bolt) CoolAnimations::lightningBolt($pos, $this->world);
  }

  // call this function when someone dies or quits!
  // we store keys by xuid
  protected final function addToLosers(SwimPlayer $swimPlayer): void
  {
    $this->losers[$swimPlayer->getXuid()] = $swimPlayer;
  }

  protected function getLoserByXuid(string $xuid): ?SwimPlayer
  {
    foreach ($this->losers as $loser) {
      if ($loser->getXuid() === $xuid) {
        if (SwimCore::$DEBUG) echo("Found player {$loser->getName()} with Xuid {$xuid}\n}");
        return $loser;
      }
    }
    return null;
  }


  protected function duelNameTag(SwimPlayer $swimPlayer): void
  {
    $swimPlayer->setNameTag(($this->getPlayerTeam($swimPlayer)?->getTeamColor() ?? "") . $swimPlayer->getNicks()->getNick());
  }

  // optional override
  protected function duelScoreTag(SwimPlayer $player): void
  {
    $cps = $player->getClickHandler()->getCPS();
    $ping = $player->getNslHandler()->getPing();
    $player->setScoreTag(TextFormat::AQUA . $cps . TextFormat::WHITE . " CPS" . TextFormat::GRAY . " | " . TextFormat::AQUA . $ping . TextFormat::WHITE . " MS");
  }

  /**
   * Fills out the first 3 lines of the board, so you must set the next line at 4
   * @param SwimPlayer $player
   * @throws ScoreFactoryException
   */
  protected function startDuelScoreBoard(SwimPlayer $player): void
  {
    $player->refreshScoreboard(TextFormat::AQUA . "Swimgg.club");
    ScoreFactory::sendObjective($player);

    // variables needed
    $ping = $player->getNslHandler()->getPing();
    $time = TimeHelper::digitalClockFormatter($this->seconds);

    // define starting lines
    ScoreFactory::setScoreLine($player, 1, " §bPing: §3" . $ping);
    ScoreFactory::setScoreLine($player, 2, " §bTime: §3" . $time);
  }

  /**
   * @throws ScoreFactoryException
   */
  protected function submitScoreboardWithBottomFromLine(SwimPlayer $player): void
  {
    // ScoreFactory::setScoreLine($player, $line, " §bdiscord.gg/§3swim"); // promotion was kinda shoved down your throat for no reason
    ScoreFactory::sendLines($player);
  }

  /**
   * The purpose of this function is to guarantee the correct type of overridden duel scoreboard is called for all players
   * This is why the duel method has to be passed to itself for abusing polymorphism
   * @throws ScoreFactoryException
   */
  protected function updateBoardsForAll(Duel $duel): void
  {
    foreach ($this->players as $player) {
      $duel->duelScoreboard($player);
    }
  }

  /**
   * @throws ScoreFactoryException
   * @breif optional override
   */
  public function duelScoreboard(SwimPlayer $player): void
  {
    if ($player->isScoreboardEnabled()) {
      try {
        $this->startDuelScoreBoard($player);
        $this->submitScoreboardWithBottomFromLine($player);
      } catch (ScoreFactoryException $e) {
        Server::getInstance()->getLogger()->info($e->getMessage());
      }
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  protected function duelScoreboardWithScoreSpectator(SwimPlayer $player): void
  {
    $this->startDuelScoreBoard($player);
    $line = 3;
    // Now iterate all the other teams and paste in their scores underneath with their team color
    foreach ($this->teamManager->getTeams() as $team) {
      if ($team->isSpecTeam()) continue; // skip spectator teams
      ScoreFactory::setScoreLine($player, ++$line, " " . $team->getFormattedScore()); // place the line for the other team's score
    }

    // Send all lines to scoreboard
    $this->submitScoreboardWithBottomFromLine($player);
  }

  /**
   * @throws ScoreFactoryException
   */
  protected function duelScoreboardWithScore(SwimPlayer $player): void
  {
    if ($player->isScoreboardEnabled()) {
      try {
        $myTeam = $this->getPlayerTeam($player);
        // if no team then use default duel board (suggest creating a neutral all-scores view instead)
        if ($myTeam === null || $myTeam->isSpecTeam()) { // this is sus because how would your team be null?
          $this->duelScoreboardWithScoreSpectator($player);
          return;
        }

        // scoreboard format helpers and stuff
        $this->startDuelScoreBoard($player);

        $kills = $player->getAttributes()->getAttribute("kills") ?? 0;
        $deaths = $player->getAttributes()->getAttribute("deaths") ?? 0;
        $kdr = $deaths > 0 ? round($kills / $deaths, 1) : $kills;  // KDR calculated and rounded to one decimal place
        $spacer = TextFormat::GRAY . " | ";
        $kdrText = TextFormat::GREEN . " K: " . $kills . $spacer . TextFormat::DARK_RED . "D: " . $deaths . $spacer . TextFormat::YELLOW . "R: " . $kdr;

        // put in our score since we are an in-game team
        ScoreFactory::setScoreLine($player, 3, $kdrText);  // Display KDR above scores
        ScoreFactory::setScoreLine($player, 4, " " . $myTeam->getFormattedScore());
        $line = 4; // Adjust line count since KDR was added

        // Now iterate all the other teams and paste in their scores underneath with their team color
        foreach ($this->teamManager->getTeams() as $team) {
          if ($team === $myTeam || $team->isSpecTeam()) continue; // skip our self and spectator teams
          ScoreFactory::setScoreLine($player, ++$line, " " . $team->getFormattedScore()); // place the line for the other team's score
        }

        // Send all lines to scoreboard
        $this->submitScoreboardWithBottomFromLine($player);
      } catch (ScoreFactoryException $e) {
        Server::getInstance()->getLogger()->info($e->getMessage());
      }
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  public function updateSecond(): void
  {
    parent::updateSecond();

    $this->seconds++;
    $startDuel = false; // Flag to indicate if the duel should start

    // if we are in the finished state then it counts to 5 and ends
    if ($this->finished) {
      $this->secondsFinished++;
      if ($this->secondsFinished >= 5) {
        $this->end();
      }
      return;
    }

    // updates all players scoreboards and score tags each second
    foreach ($this->players as $player) {
      if ($this->updateScoreBoardEachSecond) $this->duelScoreboard($player);
      if ($this->updateScoreTagEachSecond) $this->duelScoreTag($player);

      if (!$this->started) {
        $startDuel = $this->countDown($player) || $startDuel; // Update flag based on countdown
      }
    }

    // if we should start the duel this second
    if ($startDuel) {
      $this->startDuelForAllPlayers();
    }

    $this->duelUpdateSecond();
  }

  /**
   * @throws ScoreFactoryException
   * @brief sends all back to hub and deletes the scene
   */
  private function end(): void
  {
    $this->sendToHub();
    $this->sceneSystem->removeScene($this->sceneName);
  }

  private function countDown(SwimPlayer $swimPlayer): bool
  {
    if ($this->seconds <= $this->duelCountDownTime) {
      if ($this->messageCountDown) {
        $swimPlayer->sendMessage(TextFormat::GREEN . "Duel starting in " . TextFormat::YELLOW . ($this->duelCountDownTime + 1) - $this->seconds);
      }
      ServerSounds::playSoundToPlayer($swimPlayer, "random.click", 2, 1);
      return false;
    } else {
      $swimPlayer->sendMessage(TextFormat::GREEN . "Duel Started!");
      ServerSounds::playSoundToPlayer($swimPlayer, "random.orb", 2, 1);
      return true;
    }
  }

  protected function startDuelForAllPlayers(): void
  {
    $this->duelStart();
    foreach ($this->teamManager->getTeams() as $team) {
      if ($team->isSpecTeam()) continue;
      foreach ($team->getPlayers() as $swimPlayer) {
        $swimPlayer->setNoClientPredictions(false); // Allow them to move
        $this->applyKit($swimPlayer);
      }
    }
    $this->started = true; // Set the duel as started
  }

  // called when duel starts
  protected function duelStart(): void
  {
    // optional override
  }

  abstract protected function applyKit(SwimPlayer $swimPlayer): void;

  // optional override
  protected function duelUpdateSecond(): void
  {

  }

  protected final function specMessage(): void
  {
    if (count($this->players) <= 2) {
      return; // don't do a message if no one watching
    }

    $team = $this->teamManager->getSpecTeam();
    $spectators = $team->getPlayers();
    $amount = count($spectators);

    // if we have spectators
    if ($amount > 0) {
      // add all the spectator nicks into an array
      $spectatorNicks = array_map(function ($player) {
        return $player->getNicks()->getNick();
      }, $spectators);

      // get all the original players from each team in that duel and add their nicks to an array to then filter out,
      // because we don't consider original players as spectators
      $originals = array();
      $teams = $this->teamManager->getTeams();
      foreach ($teams as $team) {
        if (!$team->isSpecTeam()) {
          foreach ($team->getOriginalPlayers() as $player) {
            $nick = $player?->getNicks()?->getNick() ?? "";
            if ($nick != "") {
              $originals[] = $nick;
            }
          }
        }
      }

      // filter out original players from spectator nicks
      $filteredSpectatorNicks = array_diff($spectatorNicks, $originals);

      $msg = implode(', ', $filteredSpectatorNicks);
      $this->sceneAnnouncement(TextFormat::AQUA . "Spectators (" . count($filteredSpectatorNicks) . "): " . $msg);
    }
  }

  private function getWinningTeam(): ?Team
  {
    $populatedTeamsCount = 0;
    $winningTeam = null;

    $teams = $this->teamManager->getTeams();
    foreach ($teams as $team) {
      if (!$team->isSpecTeam() && !empty($team->getPlayers())) {
        $populatedTeamsCount++;
        $winningTeam = $team;

        if ($populatedTeamsCount > 1) {
          return null; // More than one non-spectator team is populated, this means there is no winner yet
        }
      }
    }

    return $populatedTeamsCount == 1 ? $winningTeam : null;
  }

  // on removing a player from a scene, if not finished, and they were not a spectator, then it is an elimination
  public function playerRemoved(SwimPlayer $player): void
  {
    if (!$this->finished) {
      $team = $this->getPlayerTeam($player);
      if ($team && !$team->isSpecTeam()) {
        $this->playerElimination($player);
      }
    }
  }

  /*
   * things that consider a duel over via player elimination:
   * no players left on any other teams except for one
   * TO DO: duel clean up if no winning team possible
   */
  protected final function playerElimination(SwimPlayer $swimPlayer): void
  {
    if (!$this->finished) {
      $team = $this->getPlayerTeam($swimPlayer);
      if ($team && !$team->isSpecTeam()) {
        $this->addToLosers($swimPlayer); // put them in the losers list
        $this->getPlayerTeam($swimPlayer)?->removePlayer($swimPlayer); // remove from their old team they were playing in
        $this->teamManager->getSpecTeam()->addPlayer($swimPlayer); // adding to spec team will reset the player's inventory and put them in spectator for us
        $winningTeam = $this->getWinningTeam();
        if (isset($winningTeam)) {
          $this->handleWin($winningTeam, $this->teamManager->getFirstOpposingTeam($winningTeam));
        }
      }
    }
  }

  public final function scoreBasedDuelEnd(Team $winningTeam): void
  {
    if ($this->finished) return;
    // for all players on losing teams, kill them and add to losers
    $losingTeam = null;
    $teams = $this->teamManager->getTeams();
    foreach ($teams as $team) {
      if ($winningTeam !== $team && !$team->isSpecTeam()) {
        foreach ($team->getPlayers() as $player) {
          $this->deathEffect($player);
          $player->setGameMode(GameMode::SPECTATOR());
          // remove from the team and make them a loser
          $team->removePlayer($player);
          $this->addToLosers($player);
          $losingTeam = $team; // save the losing team for later
        }
      }
    }
    // now end for real
    $this->handleWin($winningTeam, $losingTeam);
  }

  // marks the duel as finished and calls the duel over virtual function
  // when a duel is marked finished it internally counts 100 ticks then sends everyone to hub and registers its self
  protected final function handleWin(Team $winners, Team $losers): void
  {
    $this->finished = true;
    $this->duelOver($winners, $losers);
    if (SwimCore::$DEBUG) $this->dumpDuel();
    // some better UX
    foreach ($this->players as $player) {
      if ($this->getPlayerTeam($player) === $winners) {
        $player->sendTitle(TextFormat::GREEN . "VICTORY", "You Won!", 5, 60, 5);
      } else {
        $player->sendTitle(TextFormat::RED . "Game Over", "Warping to hub..", 5, 60, 5);
      }
    }
  }

  public function exit(): void
  {
    parent::exit();
    $this->map->setActive(false);
  }

  /**
   * @throws ScoreFactoryException
   */
  private function sendToHub(): void
  {
    foreach ($this->players as $player) {
      $sh = $player->getSceneHelper();
      $sh->setNewScene('Hub');
      if ($sh->isInParty()) {
        $party = $sh->getParty();
        $party->setInDuel(false); // remember to set to false
        $party->partyHubKit($player, $party->isPartyLeader($player));
      }
    }
  }

  // specific things that need to happen when the duel ends, passes the winning team swim player array as an argument
  abstract protected function duelOver(Team $winners, Team $losers): void;

  /**
   * @return bool
   */
  public function isPartyDuel(): bool
  {
    return $this->isPartyDuel;
  }

  /**
   * @param bool $isPartyDuel
   */
  public function setIsPartyDuel(bool $isPartyDuel): void
  {
    $this->isPartyDuel = $isPartyDuel;
  }

  /**
   * @param Team $winningTeam
   * @return Team|null
   * @brief gets the first losing team that isn't a spectator team
   */
  public function getPartyDuelLosingTeam(Team $winningTeam): ?Team
  {
    foreach ($this->teamManager->getTeams() as $team) {
      if ($team !== $winningTeam && !$team->isSpecTeam()) return $team;
    }
    return null;
  }

  // Commonly used constant for Elo systems
  private const K_FACTOR = 32;

  /**
   * Calculate Elo gain/loss for the winner and loser.
   *
   * @param int $winnerElo The current Elo of the winner
   * @param int $loserElo The current Elo of the loser
   * @return array An associative array with the new Elo ratings for winner and loser
   */
  #[ArrayShape(['winnerElo' => "float", 'loserElo' => "float"])] public static function calculateElo(int $winnerElo, int $loserElo): array
  {
    // Calculate expected scores
    $expectedWinner = 1 / (1 + pow(10, ($loserElo - $winnerElo) / 400));
    $expectedLoser = 1 / (1 + pow(10, ($winnerElo - $loserElo) / 400));

    // Calculate new Elo ratings
    $winnerNewElo = $winnerElo + self::K_FACTOR * (1 - $expectedWinner);
    $loserNewElo = $loserElo + self::K_FACTOR * (0 - $expectedLoser);

    // Return new ratings
    return [
      'winnerElo' => round($winnerNewElo),
      'loserElo' => round($loserNewElo)
    ];
  }

  public function dumpDuel(): void
  {
    echo "\n" . $this->sceneName . " {\n";

    // Dump teams and player information
    foreach ($this->teamManager->getTeams() as $team) {
      echo "\n";
      $name = $team->getTeamName();
      $score = $team->getScore();
      echo $name . " | Score: " . $score . "\n";
      $names = array();
      foreach ($team->getPlayers() as $player) {
        $names[] = $player->getName();
      }
      if (!empty($names)) {
        $nameStr = implode(', ', $names);
        echo $nameStr . "\n";
      }
    }

    // Dump PVP stats
    echo "\nPVP Stats:\n";
    echo "Vertical Knockback: " . $this->vertKB . "\n";
    echo "Horizontal Knockback: " . $this->kb . "\n";
    echo "Controller Vertical Knockback: " . $this->controllerVertKB . "\n";
    echo "Controller Horizontal Knockback: " . $this->controllerKB . "\n";
    echo "Hit Cooldown: " . $this->hitCoolDown . " ticks\n";
    echo "Ender Pearl Knockback: " . $this->pearlKB . "\n";
    echo "Snowball Knockback: " . $this->snowballKB . "\n";
    echo "Fishing Rod Knockback: " . $this->rodKB . "\n";
    echo "Arrow Knockback: " . $this->arrowKB . "\n";
    echo "Ender Pearl Speed: " . $this->pearlSpeed . "\n";
    echo "Ender Pearl Gravity: " . $this->pearlGravity . "\n";

    // Dump boolean settings
    echo "Natural Regeneration: " . ($this->naturalRegen ? "Enabled" : "Disabled") . "\n";
    echo "Fall Damage: " . ($this->fallDamage ? "Enabled" : "Disabled") . "\n";

    echo "\n}\n";
  }

  /**
   * @throws ScoreFactoryException
   */
  public function sceneItemUseEvent(PlayerItemUseEvent $event, SwimPlayer $swimPlayer): void
  {
    if (!$this->spectatorControls($event, $swimPlayer)) {
      parent::sceneItemUseEvent($event, $swimPlayer);
    }
  }

  /**
   * @return bool determining if we did an action or not
   * @throws ScoreFactoryException
   */
  protected final function spectatorControls(PlayerItemUseEvent $event, SwimPlayer $swimPlayer): bool
  {
    if ($swimPlayer->getGamemode() == GameMode::SPECTATOR) {
      $itemName = $event->getItem()->getCustomName();
      if ($itemName == TextFormat::RED . "Leave") {
        $swimPlayer->getSceneHelper()->setNewScene('Hub');
        $swimPlayer->sendMessage("§7Teleporting to hub...");
        $this->sceneAnnouncement(TextFormat::AQUA . $swimPlayer->getNicks()->getNick() . " Stopped Spectating");
        return true;
      }
    }

    return false;
  }

}