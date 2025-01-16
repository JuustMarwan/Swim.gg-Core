<?php

namespace core\scenes\hub;

use core\custom\behaviors\player_event_behaviors\MaxDistance;
use core\custom\prefabs\hub\HubEntities;
use core\scenes\duel\Boxing;
use core\scenes\duel\Duel;
use core\scenes\duel\Midfight;
use core\scenes\duel\Nodebuff;
use core\SwimCore;
use core\systems\map\MapsData;
use core\systems\player\SwimPlayer;
use core\systems\scene\misc\Team;
use core\systems\scene\Scene;
use core\utils\BehaviorEventEnums;
use core\utils\PositionHelper;
use core\utils\TimeHelper;
use jackmd\scorefactory\ScoreFactory;
use jackmd\scorefactory\ScoreFactoryException;
use pocketmine\block\utils\DyeColor;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use ReflectionException;

// To anyone reading this from the SwimCore public repo, I would honestly recommend making your own Queue system to fit your server's needs best.
// Making the queue system just another scene is cool, but ended up not scaling super well and has some tech debt to it depending on what you want.
// Like everything in this repo, just use the majority of this code as a learning tool and not an as-is solution for every type of server right away.
// The main thing to take away from this class is it shows how to dynamically create + register and add players into duel scenes.
class Queue extends Scene
{

  private MapsData $mapsData;
  private ?World $duelWorld;
  private ?World $miscWorld; // bridge, bed fight, battle rush

  public static function AutoLoad(): bool
  {
    return true;
  }

  /**
   * @throws ReflectionException
   */
  public function init(): void
  {
    $this->registerCanceledEvents([
      BehaviorEventEnums::ENTITY_DAMAGE_EVENT,
      BehaviorEventEnums::ENTITY_DAMAGE_BY_ENTITY_EVENT,
      BehaviorEventEnums::ENTITY_DAMAGE_BY_CHILD_ENTITY_EVENT,
      BehaviorEventEnums::PLAYER_DROP_ITEM_EVENT,
      BehaviorEventEnums::PROJECTILE_LAUNCH_EVENT,
      BehaviorEventEnums::BLOCK_BREAK_EVENT,
      BehaviorEventEnums::BLOCK_PLACE_EVENT,
      BehaviorEventEnums::PLAYER_ITEM_CONSUME_EVENT
    ]);

    // set up worlds
    $worldManager = $this->core->getServer()->getWorldManager();
    $this->duelWorld = $worldManager->getWorldByName('duels');
    $this->miscWorld = $worldManager->getWorldByName('miscDuels');

    // spawn hub entities
    HubEntities::spawnToScene($this);

    // get maps data system
    $this->mapsData = $this->core->getSystemManager()->getMapsData();

    // we need to make teams
    $this->initTeams();
  }

  private function initTeams(): void
  {
    foreach (Duel::$MODES as $mode) {
      $this->teamManager->makeTeam($mode, TextFormat::RESET, true);
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  protected function checkQueues(): void
  {
    foreach ($this->teamManager->getTeams() as $team) {
      $team->pruneOffline(); // prune it first for safety
      $size = $team->getTeamSize();
      $requiredSize = $team->getScore();
      if ($requiredSize > 2) continue; // avoid queuing games that need a team size of over 2 as we need more special queue logic for that
      if ($size >= $requiredSize || (SwimCore::$DEBUG && $size >= 1)) { // can self queue in debug mode
        // we can start the duel if the mode has an available map
        if ($this->mapsData->modeHasAvailableMap(strtolower($team->getTeamName()))) {
          $this->startDuel($team);
          break; // to prevent a weird bug that was caused when players join the queue in the same tick. This is shoving the problem under the bed, but it should be fine now.
        }
      }
    }
  }

  public function getWorldBasedOnMode(string $mode): World
  {
    return match ($mode) {
      default => $this->duelWorld,
      'bridge', 'bedfight', 'battlerush', => $this->miscWorld
    };
  }

  /**
   * @throws ScoreFactoryException
   */
  private function startDuel(Team $team): void
  {
    $players = $team->getFirstTwoPlayers();

    if (SwimCore::$DEBUG && isset($players[0])) { // in debug, you can queue your self to solo test games
      $this->publicDuelStart($players[0], $players[0], $team->getTeamName());
    } elseif (isset($players[0]) && isset($players[1])) {
      // stupid fix to make sure they are online before doing a queue
      if ($players[0] instanceof SwimPlayer && $players[1] instanceof SwimPlayer) {
        if ($players[0]->isOnline() && $players[1]->isOnline()) {
          $this->publicDuelStart($players[0], $players[1], $team->getTeamName());
        }
      }
    }
  }

  // party also uses this method when it is making a party duel
  public function makeDuelSceneFromMode(string $mode, string $duelName): ?Duel
  {
    $world = $this->getWorldBasedOnMode($mode);
    return match ($mode) {
      'nodebuff' => new Nodebuff($this->core, $duelName, $world),
      'boxing' => new Boxing($this->core, $duelName, $world),
      'midfight' => new Midfight($this->core, $duelName, $world),
      default => null
    };
  }

  /**
   * @throws ScoreFactoryException
   */
  public function publicDuelStart(SwimPlayer $playerOne, SwimPlayer $playerTwo, string $mode, string $mapName = 'random'): void
  {
    // make duel name of player nicks
    $nameOne = $playerOne->getNicks()->getNick();
    $nameTwo = $playerTwo->getNicks()->getNick();
    $duelName = $mode . ': ' . $nameOne . ' vs ' . $nameTwo;

    // unique team 2 name when in debug
    if (SwimCore::$DEBUG) {
      $nameTwo .= " Debug";
    }

    // make the correct duel type
    $duel = $this->makeDuelSceneFromMode($mode, $duelName);

    // just in case
    if ($duel == null) return;

    // make the teams and register the scene TO DO | make sure the scenes set these team values properly such as respawn and target score
    $teamOne = $duel->teamManager->makeTeam($nameOne, TextFormat::RED, false, 3);
    $teamTwo = $duel->teamManager->makeTeam($nameTwo, TextFormat::BLUE, false, 3);
    $this->sceneSystem->registerScene($duel, $duelName, false);

    // get a map
    if ($mapName === 'random') {
      $map = $this->mapsData->getRandomMapFromMode($mode);
    } else {
      $map = $this->mapsData->getNamedMapFromMode($mode, $mapName);
    }
    if ($map == null) return; // this is awful if this happens
    $map->setActive(true);
    $duel->setMap($map);

    // set spawn points to correct world
    $world = $this->getWorldBasedOnMode($mode);
    $teamOne->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos1(), $world));
    $teamTwo->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos2(), $world));

    // now move the players into the duel
    $playerOne->getSceneHelper()->setNewScene($duelName);
    $playerTwo->getSceneHelper()->setNewScene($duelName);

    // set teams
    $teamOne->addPlayer($playerOne);
    $teamTwo->addPlayer($playerTwo);

    // init the duel now that we set all this data
    $duel->init();

    // say map the match is on and the opponent
    $duel->sceneAnnouncement(TextFormat::GREEN . "Found Match on: " . TextFormat::YELLOW . $map->getMapName());
    $playerOne->sendMessage(TextFormat::GREEN . "Opponent: " . $playerTwo->getRank()->rankString());
    $playerTwo->sendMessage(TextFormat::GREEN . "Opponent: " . $playerOne->getRank()->rankString());

    // warp in physically
    $duel->warpPlayersIn();
    if (SwimCore::$DEBUG) $duel->dumpDuel();
  }

  /**
   * @throws ScoreFactoryException
   */
  public function updateSecond(): void
  {
    if (!empty($this->players)) {
      $this->checkQueues();
      foreach ($this->teamManager->getTeams() as $team) {
        $teamName = $team->getTeamName();
        foreach ($team->getPlayers() as $player) {
          $this->queueTag($player);
          $this->queueBoard($player, $teamName);
        }
      }
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  private function queueBoard(SwimPlayer $swimPlayer, string $mode): void
  {
    $attributes = $swimPlayer->getAttributes();
    $time = $attributes->getAttribute('seconds') + 1;
    $attributes->setAttribute('seconds', $time);

    if ($swimPlayer->isScoreboardEnabled()) {
      try {
        $swimPlayer->refreshScoreboard(TextFormat::AQUA . "Swimgg.club");
        ScoreFactory::sendObjective($swimPlayer);
        // variables needed
        $onlineCount = count($swimPlayer->getServer()->getOnlinePlayers());
        $maxPlayers = $swimPlayer->getServer()->getMaxPlayers();
        $ping = $swimPlayer->getNslHandler()->getPing();
        $time = TimeHelper::digitalClockFormatter($time);
        $line = 0;
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §bOnline: §f" . $onlineCount . "§7 / §3" . $maxPlayers);
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §bPing: §3" . $ping);
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §bQueued: §3" . $this->sceneSystem->getQueuedCount());
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §bIn Duel: §3" . $this->sceneSystem->getInDuelsCount());
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §bQueuing: §3" . ucfirst($mode));
        ScoreFactory::setScoreLine($swimPlayer, ++$line, " §b" . $time);
        // send lines
        ScoreFactory::sendLines($swimPlayer);
      } catch (ScoreFactoryException $e) {
        Server::getInstance()->getLogger()->info($e->getMessage());
      }
    }
  }

  public function playerAdded(SwimPlayer $player): void
  {
    $player->getEventBehaviorComponentManager()->registerComponent(new MaxDistance("max", $this->core, $player));
    $player->getAttributes()->setAttribute('seconds', 0);
    $player->getCosmetics()->refresh();
    $this->queueKit($player);
  }

  private function queueKit(Player $player): void
  {
    $player->setGamemode(GameMode::ADVENTURE);
    $player->getInventory()->setItem(0, VanillaItems::DYE()->setColor(DyeColor::RED())->setCustomName(TextFormat::RED . "Leave Queue"));
  }

  /**
   * @throws ScoreFactoryException
   */
  function sceneItemUseEvent(PlayerItemUseEvent $event, SwimPlayer $swimPlayer): void
  {
    if ($event->getItem()->getName() == TextFormat::RED . "Leave Queue") {
      $swimPlayer->sendMessage(TextFormat::YELLOW . "Left the Queue");
      $swimPlayer->getSceneHelper()->setNewScene('Hub');
    }
  }

  private function queueTag(SwimPlayer $swimPlayer): void
  {
    $swimPlayer->genericNameTagHandling();
    $swimPlayer->getCosmetics()->tagNameTag();
    $teamName = $this->getPlayerTeam($swimPlayer)?->getTeamName();
    if (!$teamName) return;
    $swimPlayer->setScoreTag(TextFormat::GREEN . "Queuing " . TextFormat::YELLOW . ucfirst($teamName));
  }

}