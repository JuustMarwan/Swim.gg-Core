<?php

namespace core\systems\party;

use core\forms\parties\FormPartyDuels;
use core\forms\parties\FormPartyExit;
use core\forms\parties\FormPartyInvite;
use core\forms\parties\FormPartyManagePlayers;
use core\forms\parties\FormPartySettings;
use core\scenes\hub\Queue;
use core\SwimCore;
use core\systems\map\MapsData;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use core\systems\scene\SceneSystem;
use core\utils\PositionHelper;
use jackmd\scorefactory\ScoreFactoryException;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\utils\TextFormat;

class Party
{

  /**
   * @var SwimPlayer[]
   */
  private array $players;

  // requests
  private array $duelRequests;
  private array $joinRequests;

  private string $partyName;
  private int $maxPartySize;
  private int $currentPartySize;

  private SwimPlayer $leader;
  private SwimCore $core;

  // party settings
  private array $settings;

  // activity info
  private bool $inDuel = false;

  // helper systems for making party duels
  private SceneSystem $sceneSystem;
  private Queue $queueScene;
  private MapsData $mapsData;

  // pvp config fields:
  public float $vertKB = 0.4;
  public float $horKB = 0.4;
  public float $controllerVertKB = 0.4;
  public float $controllerKB = 0.4;
  public int $hitCoolDown = 10; // in ticks
  public float $pearlKB = 0.6;
  public float $snowballKB = 0.5;
  public float $rodKB = 0.35;
  public float $arrowKB = 0.5;
  public float $pearlSpeed = 2.5;
  public float $pearlGravity = 0.1;
  public bool $naturalRegen = true;
  public bool $fallDamage = false;

  public function __construct(SwimCore $core, string $name, SwimPlayer $leader)
  {
    $this->core = $core;
    $this->players = [];
    $this->currentPartySize = 0;
    $this->duelRequests = [];
    $this->joinRequests = [];
    $this->partyName = $name;
    $this->leader = $leader;

    // determine the max party size once setting leader
    $this->determineMaxPartySize();

    // toggles
    $this->settings = [
      'random' => true, // randomized teams for self duels
      'allowDuelInvites' => true, // Allows parties to send you duel requests
      'allowJoinRequests' => true, // Allows people to request to join your party
      'openJoin' => false, // Allows people to openly join your party right away
      'membersCanInvite' => false, // Allows party members to invite people to your party
      'membersCanAllowJoin' => false, // Allows party members to accept join requests to your party
      'membersCanQueue' => false, // Allows party members to start self-party duels
      'membersCanDuel' => false, // Allows party members to duel request other parties
      'membersCanAcceptDuel' => false, // Allows party members to accept duel requests from other parties
    ];

    // announcement
    $leader->getSceneHelper()->getScene()->sceneAnnouncement(
      TextFormat::GREEN . $leader->getNicks()->getNick() . " Created a Party: " . TextFormat::YELLOW . $this->partyName
    );

    // add leader
    $this->addPlayerToParty($leader, false);

    // cache queue scene, we need its methods for creating a duel (shortcut)
    $sm = $this->core->getSystemManager();
    $this->sceneSystem = $sm->getSceneSystem();
    $this->mapsData = $sm->getMapsData();
    $scene = $this->sceneSystem->getScene("Queue");
    if ($scene instanceof Queue) {
      $this->queueScene = $scene;
    }
  }

  // must be set and not in a duel
  // kinda scuffed function only used once
  public static function shouldInvite(?Party $party): bool
  {
    if (!isset($party)) return false;

    if ($party->inDuel) return false;

    return true;
  }

  public function setHubKits(): void
  {
    // don't want to do this while in a duel
    if ($this->isInDuel()) return;

    foreach ($this->players as $player) {
      $isLeader = $player === $this->leader;
      $this->partyHubKit($player, $isLeader);
    }
  }

  // set a player's hub kit when in a party (needs to pass in the party they are in as well)
  public function partyHubKit(SwimPlayer $player, bool $isLeader): void
  {
    $player->getInventory()->clearAll();

    // Define party kit items and their corresponding settings
    $items = [
      0 => ['item' => VanillaBlocks::CAKE()->asItem()->setCustomName("§dParty Games §7[Right Click]"), 'setting' => 'membersCanQueue'],
      1 => ['item' => VanillaItems::GOLDEN_APPLE()->setCustomName("§gView Party Duel Requests §7[Right Click]"), 'setting' => 'membersCanAcceptDuel'],
      2 => ['item' => VanillaItems::GOLDEN_CARROT()->setCustomName("§gSend a Party Duel Request §7[Right Click]"), 'setting' => 'membersCanDuel'],
      3 => ['item' => VanillaItems::COOKIE()->setCustomName("§6Invite a Player §7[Right Click]"), 'setting' => 'membersCanInvite'],
      4 => ['item' => VanillaItems::DIAMOND()->setCustomName("§bView Party Join Requests §7[Right Click]"), 'setting' => 'membersCanAllowJoin'],
      8 => ['item' => VanillaItems::DYE()->setColor(DyeColor::RED())->setCustomName($isLeader ? "§cDisband Party §7[Right Click]" : "§cLeave Party §7[Right Click]"), 'setting' => null]
    ];

    // leader only items
    if ($isLeader) {
      $items[5] = ['item' => VanillaItems::ENCHANTED_BOOK()->setCustomName("§5Party Settings §7[Right Click]"), 'setting' => null];
      $items[7] = ['item' => VanillaItems::ENDER_PEARL()->setCustomName("§1Manage Party §7[Right Click]"), 'setting' => null];
    }

    // Add the items based on the player's settings
    foreach ($items as $slot => $data) {
      if ($isLeader || $data['setting'] === null || $this->getSetting($data['setting'])) {
        $player->getInventory()->setItem($slot, $data['item']);
      }
    }

    // set party score tag
    $player->setScoreTag(TextFormat::GREEN . $this->partyName . TextFormat::GRAY . " | " . $this->formatSize());
  }

  // HORRIBLE : need to make custom item on use callback
  public function partyItemHandle(SwimPlayer $player, string $customName): void
  {
    switch ($customName) {
      case "§dParty Games §7[Right Click]":
        FormPartyDuels::baseForm($this->core, $player, $this);
        break;

      case "§gView Party Duel Requests §7[Right Click]":
        FormPartyDuels::acceptPartyDuelRequests($player, $this);
        break;

      case "§gSend a Party Duel Request §7[Right Click]":
        FormPartyDuels::pickOtherPartyToDuel($this->core, $player, $this);
        break;

      case "§6Invite a Player §7[Right Click]":
        FormPartyInvite::formPartyInvite($this->core, $player, $this);
        break;

      case "§bView Party Join Requests §7[Right Click]":
        FormPartyInvite::formPartyRequests($this->core, $player, $this);
        break;

      case "§cDisband Party §7[Right Click]":
        FormPartyExit::formPartyDisband($this->core, $player, $this);
        break;

      case "§cLeave Party §7[Right Click]":
        FormPartyExit::formPartyLeave($this->core, $player, $this);
        break;

      case "§5Party Settings §7[Right Click]":
        FormPartySettings::baseSelection($this->core, $player, $this);
        break;

      case "§1Manage Party §7[Right Click]":
        FormPartyManagePlayers::listPlayers($this->core, $player, $this);
        break;
    }
  }

  public function invitePlayer(SwimPlayer $invited, SwimPlayer $inviter): void
  {
    if ($invited->isConnected() && $invited->isInScene("Hub") && !$invited->getSceneHelper()?->isInParty()) {
      if (Party::shouldInvite($this)) {
        $invited->getInvites()->partyInvitePlayer($inviter, $this);
      }
    } else {
      $inviter->sendMessage(TextFormat::YELLOW . $invited->getNicks()->getNick() . TextFormat::RED . " is no longer in the Hub or Server");
    }
  }

  private function determineMaxPartySize(): void
  {
    $rankLevel = $this->leader->getRank()->getRankLevel();
    if ($rankLevel == Rank::DEFAULT_RANK) {
      $this->maxPartySize = 4;
    } else if ($rankLevel >= Rank::BOOSTER_RANK && $rankLevel <= Rank::FAMOUS_RANK) {
      $this->maxPartySize = 8;
    } else {
      // $this->maxPartySize = $this->core->getServer()->getMaxPlayers(); // might want to limit this depending on how abused it gets
      $this->maxPartySize = 99;
    }
  }

  public function getCurrentPartySize(): int
  {
    return $this->currentPartySize;
  }

  public function getMaxPartySize(): int
  {
    return $this->maxPartySize;
  }

  public function getPartyName(): string
  {
    return $this->partyName;
  }

  public function setPartyName(string $name): void
  {
    $this->partyName = $name;
  }

  public function getPlayers(): array
  {
    return $this->players;
  }

  public function getPartyLeader(): SwimPlayer
  {
    return $this->leader;
  }

  public function isPartyLeader(SwimPlayer $player): bool
  {
    return $player === $this->leader;
  }

  // this should be called with care! Intended for when the leader has already left the data structure, or when manually switching the party leader
  // this just gets the first person in the party, maybe we should instead make it be the person with the highest rank?
  public function assignNewPartyLeader(): ?SwimPlayer
  {
    $leader = reset($this->players);
    if ($leader) {
      $this->leader = $leader;// first player in the array
      $this->leader->sendMessage(TextFormat::GREEN . "You are now the party leader of " . TextFormat::YELLOW . $this->partyName);
      $this->determineMaxPartySize(); // need to redetermine size
      $this->setHubKits();
      return $this->leader; // also returns the new leader that was set
    }

    return null;
  }

  // setting a new party leader refreshes the maximum party size, but does not force anyone out
  // for example if size goes down to 4 and there are 8 people currently in the party, they won't get kicked but now won't have room to invite anyone
  public function setPartyLeader(SwimPlayer $player): void
  {
    $this->leader = $player;
    $this->determineMaxPartySize();
  }

  public function canAddPlayerToParty(): bool
  {
    return $this->currentPartySize < $this->maxPartySize;
  }

  public function addPlayerToParty(SwimPlayer $player, bool $msg = true): void
  {
    $player->getSceneHelper()->setParty($this);
    $this->players[$player->getId()] = $player;
    $this->currentPartySize++;

    // optional message
    if ($msg) {
      $this->partyMessage(TextFormat::GREEN . $player->getNicks()->getNick() . " joined the Party! " . $this->formatSize());
    }

    // set party hub kit if not in duel
    if (!$this->inDuel) {
      $this->partyHubKit($player, $player === $this->leader);
    }

    $this->refreshHubScoreTagsForAll();
  }

  private function refreshHubScoreTagsForAll(): void
  {
    foreach ($this->players as $player) {
      if ($player->isConnected() && $player->isInScene("Hub")) {
        $player->setScoreTag(TextFormat::GREEN . $this->partyName . TextFormat::GRAY . " | " . $this->formatSize());
      }
    }
  }

  // this automatically handles setting a new party leader

  /**
   * @throws ScoreFactoryException
   */
  public function removePlayerFromParty(SwimPlayer $player): void
  {
    if (isset($this->players[$player->getId()])) {
      // determine if party leader left, meaning we need a new leader
      $needNewLeader = $this->isPartyLeader($player);

      // update party stats
      unset($this->players[$player->getId()]);
      $this->currentPartySize--;

      // clean player state
      $player->getSceneHelper()->getScene()->restart($player);
      $player->getSceneHelper()->setParty(null);
      $player->getInvites()->clearAllInvites(); // reset party invites as well

      $shouldRefresh = true;
      if ($this->currentPartySize <= 0) {
        // if no one left in the party, delete the party
        $this->core->getSystemManager()->getPartySystem()->disbandParty($this);
        $shouldRefresh = false;
      } else if ($needNewLeader) {
        // otherwise pick a new leader
        $newLeader = $this->assignNewPartyLeader();
        // if failed just kill off the party
        if (!$newLeader) {
          $this->core->getSystemManager()->getPartySystem()->disbandParty($this);
        }
      }
      // score tag refresh afterwards
      if ($shouldRefresh) $this->refreshHubScoreTagsForAll();
    }
  }

  public function getSetting(string $key): ?bool
  {
    if (isset($this->settings[$key])) {
      return $this->settings[$key];
    }
    return null;
  }

  public function setSetting(string $key, $value): void
  {
    // check if it is set to avoid accidentally adding extra settings, settings fields only be registered in the constructor
    if (isset($this->settings[$key])) {
      $this->settings[$key] = $value;
    }
  }

  public function isInDuel(): bool
  {
    return $this->inDuel;
  }

  public function setInDuel(bool $status): void
  {
    $this->inDuel = $status;
  }

  public function duelInvite(SwimPlayer $sender, Party $senderParty, string $mode, string $mapName = 'random'): void
  {
    // Check if a duel request has already been sent
    $str = TextFormat::GREEN . $senderParty->getPartyName() . TextFormat::DARK_GRAY . " | " . TextFormat::RED . ucfirst($mode);

    if (isset($this->duelRequests[$str])) {
      $sender->sendMessage(TextFormat::YELLOW . "You already sent " . $this->partyName . " this duel request!");
      return;
    }

    // Set the duel request with mode and map name
    $this->duelRequests[$str] = ["party" => $senderParty, "mode" => $mode, 'map' => $mapName];

    // Format the map name with proper colors
    $formattedMap = ($mapName === 'random') ? TextFormat::GRAY . "a Random Map" : TextFormat::AQUA . ucfirst($mapName);

    // Messages sent to the sender party and the receiver party
    $senderParty->partyMessage(
      TextFormat::GREEN . "Sent a " . TextFormat::YELLOW . ucfirst($mode)
      . TextFormat::GREEN . " Party Duel Request to " . TextFormat::YELLOW . $this->partyName
      . TextFormat::GREEN . " on " . $formattedMap
    );

    $this->partyMessage(
      TextFormat::GREEN . "Received a " . TextFormat::YELLOW . ucfirst($mode)
      . TextFormat::GREEN . " Party Duel Request from " . TextFormat::YELLOW . $senderParty->getPartyName()
      . TextFormat::GREEN . " on " . $formattedMap
    );
  }


  public function clearDuelRequests(): void
  {
    $this->duelRequests = [];
  }

  public function getDuelRequests(): array
  {
    return $this->duelRequests;
  }

  public function clearJoinRequests(): void
  {
    $this->joinRequests = [];
  }

  public function getJoinRequests(): array
  {
    return $this->joinRequests;
  }

  public function partyMessage(string $message): void
  {
    foreach ($this->players as $player) {
      if ($player->isConnected()) {
        $player->sendMessage($message);
      }
    }
  }

  public function sendJoinRequest(SwimPlayer $player): void
  {
    if (!isset($this->joinRequests[$player->getName()])) {
      $this->joinRequests[$player->getName()] = $player;
      $this->partyMessage(TextFormat::YELLOW . $player->getNicks()->getNick() . TextFormat::GREEN . " Requested to join your Party");
      $player->sendMessage(TextFormat::GREEN . "Sent join request to " . TextFormat::GREEN . $this->partyName);
    } else {
      $player->sendMessage(TextFormat::YELLOW . "You already requested to join " . TextFormat::GREEN . $this->partyName);
    }
  }

  public function clearPartyData(): void
  {
    $this->clearDuelRequests();
    $this->clearJoinRequests();
  }

  public function hasPlayer(SwimPlayer $player): bool
  {
    return array_key_exists($player->getId(), $this->players);
  }

  public function formatSize(): string
  {
    return TextFormat::DARK_GRAY . "(" . TextFormat::YELLOW . $this->currentPartySize
      . TextFormat::DARK_GRAY . "/" . TextFormat::YELLOW . $this->maxPartySize . TextFormat::DARK_GRAY . ")";
  }

  public function sizeMessage(SwimPlayer $player): void
  {
    $size = $this->formatSize();

    $msg = TextFormat::YELLOW . "The Party is Full! " . $size;

    if ($this->getMaxPartySize() == 4) {
      $msg .= TextFormat::GRAY . " | " . TextFormat::YELLOW . "To get Larger Party Size, Purchase "
        . TextFormat::GREEN . " VIP " . TextFormat::GRAY . " | " . TextFormat::DARK_AQUA . "swim.tebex.io";
    }

    $player->sendMessage($msg);
  }

  /**
   * @throws ScoreFactoryException
   */
  public function startSelfDuel(string $mode, string $mapName = 'random'): void
  {
    // Get the map based on the map name or random selection
    if ($mapName === 'random') {
      $map = $this->mapsData->getRandomMapFromMode($mode);
    } else {
      $map = $this->mapsData->getFirstInactiveMapByBaseNameFromMode($mode, $mapName);
      if ($map === null || $map->mapIsActive()) {
        // If the selected map is in use, fallback to a random map
        $map = $this->mapsData->getRandomMapFromMode($mode);
        $this->partyMessage(TextFormat::YELLOW . "The selected map is in use, picked a random map instead");
      }
    }

    // If no map is available, return early
    if ($map === null) {
      $this->partyMessage(TextFormat::RED . "ERROR: No map available at this time");
      return;
    }

    // set up party values
    $this->clearPartyData();
    $this->inDuel = true;

    // Create the duel name based on the mode and party name
    $duelName = $mode . " " . $this->partyName;

    // Create a new duel scene for the mode
    $duel = $this->queueScene->makeDuelSceneFromMode($mode, $duelName, $map->getMapName());

    // Make the teams and register the scene
    $teamOne = $duel->getTeamManager()->makeTeam("Red", TextFormat::RED, false, 3);
    $teamTwo = $duel->getTeamManager()->makeTeam("Blue", TextFormat::BLUE, false, 3);
    $this->sceneSystem->registerScene($duel, $duelName, false);

    // Set the map as active and assign it to the duel
    $map->setActive(true);
    $duel->setMap($map);
    $duel->setIsPartyDuel(true);

    // Set the spawn points for the teams
    $world = $duel->getWorld();
    $teamOne->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos1(), $world));
    $teamTwo->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos2(), $world));

    // Move players into the duel
    foreach ($this->players as $player) {
      $player->getSceneHelper()->setNewScene($duelName);
    }

    // Calculate the number of players per team
    $playersCount = count($this->players);
    $playersPerTeam = (int)ceil($playersCount / 2);

    // We have to copy the data structure because we will shuffle it if randomized, and we don't want to permanently impact the order of the teams
    $tempPlayers = $this->players;

    // Shuffle the players array if randomization is enabled
    if ($this->settings['random']) {
      shuffle($tempPlayers);
    }

    // Split the players into two teams
    $teamOnePlayers = array_slice($tempPlayers, 0, $playersPerTeam);
    $teamTwoPlayers = array_slice($tempPlayers, $playersPerTeam);

    // Assign players to the Red team
    foreach ($teamOnePlayers as $player) {
      $teamOne->addPlayer($player);
    }

    // Assign players to the Blue team
    foreach ($teamTwoPlayers as $player) {
      $teamTwo->addPlayer($player);
    }

    // Initialize the duel now that all the data is set
    $duel->init();
    $duel->vertKB = $this->vertKB;
    $duel->kb = $this->horKB;
    $duel->controllerVertKB = $this->controllerVertKB;
    $duel->controllerKB = $this->controllerKB;
    $duel->hitCoolDown = $this->hitCoolDown;
    $duel->pearlKB = $this->pearlKB;
    $duel->snowballKB = $this->snowballKB;
    $duel->rodKB = $this->rodKB;
    $duel->arrowKB = $this->arrowKB;
    $duel->pearlSpeed = $this->pearlSpeed;
    $duel->pearlGravity = $this->pearlGravity;
    $duel->naturalRegen = $this->naturalRegen;
    $duel->fallDamage = $this->fallDamage;

    // Announce the map and start the duel
    $duel->sceneAnnouncement(TextFormat::GREEN . "Starting Self Party Duel on: " . TextFormat::YELLOW . $map->getMapName());

    // Warp players into the map
    $duel->warpPlayersIn();

    // Debug dump if in debug mode
    if (SwimCore::$DEBUG) {
      $duel->dumpDuel();
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  public function startPartyVsPartyDuel(Party $otherParty, string $mode, string $mapName = 'random'): void
  {
    // Get the map based on the map name or random selection
    if ($mapName === 'random') {
      $map = $this->mapsData->getRandomMapFromMode($mode);
    } else {
      $map = $this->mapsData->getFirstInactiveMapByBaseNameFromMode($mode, $mapName);
      if ($map === null || $map->mapIsActive()) {
        // If the selected map is in use, fallback to a random map
        $map = $this->mapsData->getRandomMapFromMode($mode);
        $this->partyMessage(TextFormat::YELLOW . "The selected map is in use, picked a random map instead");
      }
    }

    // If no map is available, return early
    if ($map === null) {
      $this->partyMessage(TextFormat::RED . "ERROR: No map available at this time");
      return;
    }

    // Set up party values
    $this->clearPartyData();
    $this->inDuel = true;
    $otherParty->setInDuel(true);
    $otherParty->clearPartyData();

    // Create the duel name based on the mode and party names
    $otherName = $otherParty->getPartyName();
    $duelName = $mode . " | " . $this->partyName . " vs " . $otherName;

    // Create a new duel scene for the mode
    $duel = $this->queueScene->makeDuelSceneFromMode($mode, $duelName, $map->getMapName());

    // Make the teams and register the scene
    $teamOne = $duel->getTeamManager()->makeTeam($this->partyName, TextFormat::RED, false, 3);
    $teamTwo = $duel->getTeamManager()->makeTeam($otherName, TextFormat::BLUE, false, 3);
    $this->sceneSystem->registerScene($duel, $duelName, false);

    // Set the map as active and assign it to the duel
    $map->setActive(true);
    $duel->setMap($map);
    $duel->setIsPartyDuel(true);

    // Set the spawn points for the teams
    $world = $duel->getWorld();
    $teamOne->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos1(), $world));
    $teamTwo->addSpawnPoint(0, PositionHelper::vecToPos($map->getSpawnPos2(), $world));

    // Move players from both parties into the duel
    foreach ($this->players as $player) {
      $player->getSceneHelper()->setNewScene($duelName);
      $teamOne->addPlayer($player);
    }

    foreach ($otherParty->getPlayers() as $player) {
      $player->getSceneHelper()->setNewScene($duelName);
      $teamTwo->addPlayer($player);
    }

    // Initialize the duel now that all the data is set
    $duel->init();

    // Announce the map and the opponent team
    $duel->sceneAnnouncement(TextFormat::GREEN . "Starting Party Duel on: " . TextFormat::YELLOW . $map->getMapName()
      . TextFormat::GRAY . " | " . TextFormat::YELLOW . "VS: " . $otherName);

    // Warp players into the map
    $duel->warpPlayersIn();

    // Debug dump if in debug mode
    if (SwimCore::$DEBUG) {
      $duel->dumpDuel();
    }
  }

}