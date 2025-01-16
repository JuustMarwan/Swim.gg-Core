<?php

namespace core\systems\player;

use core\SwimCore;
use core\systems\player\components\AckHandler;
use core\systems\player\components\AntiCheatData;
use core\systems\player\components\Attributes;
use core\systems\player\components\behaviors\BetterBlockBreaker;
use core\systems\player\components\behaviors\EventBehaviorComponent;
use core\systems\player\components\behaviors\EventBehaviorComponentManager;
use core\systems\player\components\ChatHandler;
use core\systems\player\components\ClickHandler;
use core\systems\player\components\CombatLogger;
use core\systems\player\components\CoolDowns;
use core\systems\player\components\Cosmetics;
use core\systems\player\components\Invites;
use core\systems\player\components\NetworkStackLatencyHandler;
use core\systems\player\components\Nicks;
use core\systems\player\components\Rank;
use core\systems\player\components\SceneHelper;
use core\systems\player\components\Settings;
use core\utils\InventoryUtil;
use core\utils\PositionHelper;
use core\utils\StackTracer;
use jackmd\scorefactory\ScoreFactory;
use jackmd\scorefactory\ScoreFactoryException;
use JsonException;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeTags;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Event;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\sound\FireExtinguishSound;
use poggit\libasynql\libs\SOFe\AwaitGenerator\Await;
use ReflectionClass;
use ReflectionException;

class SwimPlayer extends Player
{

  private SwimCore $core;

  /**
   * @var Component[]
   */
  private array $components = [];

  /**
   * @var Component[]
   */
  private array $updatable = [];

  private ?EventBehaviorComponentManager $eventBehaviorComponentManager = null;

  private ?BetterBlockBreaker $betterBlockBreaker = null;

  private ?ChatHandler $chatHandler = null;
  private ?ClickHandler $clickHandler = null;
  private ?CoolDowns $coolDowns = null;
  private ?Invites $invites = null;
  private ?Nicks $nicks = null;
  private ?Rank $rank = null;
  private ?SceneHelper $sceneHelper = null;
  private ?Settings $settings = null;
  private ?AntiCheatData $antiCheatData = null;
  private ?NetworkStackLatencyHandler $nslHandler = null;
  private ?Attributes $attributes = null;
  private ?CombatLogger $combatLogger = null;
  private ?AckHandler $ackHandler = null;
  private ?Cosmetics $cosmetics = null;
  private string $discordIdFromDb = "";

  private Vector3 $exactPosition;
  private int $ticksSinceLastTeleport = 0;
  private int $ticksSinceLastMotionSet = 0;

  private ?Position $previousPositionBeforeTeleport = null;

  private bool $loaded = false;

  public function init(SwimCore $core): void
  {
    $this->core = $core;
    $this->eventBehaviorComponentManager = new EventBehaviorComponentManager();

    // then construct all the components

    $this->chatHandler = new ChatHandler($core, $this);
    $this->components['chatHandler'] = $this->chatHandler;

    $this->clickHandler = new ClickHandler($core, $this);
    $this->components['clickHandler'] = $this->clickHandler;

    $this->coolDowns = new CoolDowns($core, $this);
    $this->components['coolDowns'] = $this->coolDowns;

    $this->invites = new Invites($core, $this);
    $this->components['invites'] = $this->invites;

    $this->nicks = new Nicks($core, $this);
    $this->components['nicks'] = $this->nicks;

    $this->rank = new Rank($core, $this);
    $this->components['rank'] = $this->rank;

    $this->sceneHelper = new SceneHelper($core, $this);
    $this->components['sceneHelper'] = $this->sceneHelper;

    $this->settings = new Settings($core, $this);
    $this->components['settings'] = $this->settings;

    $this->antiCheatData = new AntiCheatData($core, $this, true);
    $this->components['antiCheatData'] = $this->antiCheatData;

    $this->nslHandler = new NetworkStackLatencyHandler($core, $this);
    $this->components['nslHandler'] = $this->nslHandler;

    $this->attributes = new Attributes($core, $this);
    $this->components['attributes'] = $this->attributes;

    $this->combatLogger = new CombatLogger($core, $this);
    $this->components['combatLogger'] = $this->combatLogger;

    $this->ackHandler = new AckHandler($core, $this, true);
    $this->components["ackHandler"] = $this->ackHandler;

    $this->cosmetics = new Cosmetics($core, $this);
    $this->components['cosmetics'] = $this->cosmetics;

    // then init each component
    foreach ($this->components as $key => $component) {
      // add to the updatable array if it updates
      if ($component->doesUpdate()) {
        $this->updatable[$key] = $component;
        if (SwimCore::$DEBUG) echo("Adding $key component to updatable array for {$this->getName()}\n");
      }
      $component->init();
    }
  }

  public function getBehaviorManager(): ?EventBehaviorComponentManager
  {
    return $this->eventBehaviorComponentManager ?? null;
  }

  public function registerBehavior(EventBehaviorComponent $behaviorComponent): void
  {
    if (isset($this->eventBehaviorComponentManager)) {
      $this->eventBehaviorComponentManager->registerComponent($behaviorComponent);
    }
  }

  public function event(Event $event, int $eventEnum): void
  {
    if (isset($this->eventBehaviorComponentManager)) {
      $this->eventBehaviorComponentManager->event($event, $eventEnum);
    }
  }

  public function getEventBehaviorComponentManager(): ?EventBehaviorComponentManager
  {
    return $this->eventBehaviorComponentManager ?? null;
  }

  // this is now public since it is very useful,
  // we could also maybe attach a class bool field for saying if we take fall damage
  public function calculateFallDamage(float $fallDistance): float
  {
    return parent::calculateFallDamage($fallDistance);
  }

  public function getSwimCore(): SwimCore
  {
    return $this->core;
  }

  public function onUpdate(int $currentTick): bool
  {
    if ($this->betterBlockBreaker !== null && !$this->betterBlockBreaker->update()) {
      $this->betterBlockBreaker = null;
    }

    return parent::onUpdate($currentTick);
  }

  public function updateTick(): void
  {
    foreach ($this->updatable as $component) {
      // if ($component->doesUpdate()) // we never change if components are updatable or not so this is fine for now
      $component->updateTick();
    }
    $this->eventBehaviorComponentManager->updateTick();
    $this->ticksSinceLastTeleport++;
    $this->ticksSinceLastMotionSet++;
  }

  public function updateSecond(): void
  {
    foreach ($this->updatable as $component) {
      // if ($component->doesUpdate()) // we never change if components are updatable or not so this is fine for now
      $component->updateSecond();
    }
    $this->eventBehaviorComponentManager->updateSecond();
  }

  public function exit(): void
  {
    $this->saveData(); // save data first then exit all components
    foreach ($this->components as /*$key =>*/ $component) {
      $component->exit();
      // $component[$key] = null; // this could break things
    }
    $this->components = [];
    $this->updatable = [];
  }

  public function onPostDisconnect(Translatable|string $reason, Translatable|string|null $quitMessage): void
  {
    parent::onPostDisconnect($reason, $quitMessage);
    $this->betterBlockBreaker = null;
  }

  // is connected check after each query to baby-proof component loading from database
  public function loadData(): void
  {
    Await::f2c(/**
     * @throws ScoreFactoryException
     * @breif it would be better for component base class to have a load method to override, and we iterate components calling that method
     */ function () {
      if ($this->isConnected())
        yield from $this->settings->load();
      if ($this->isConnected())
        yield from $this->rank->load();
      if ($this->isConnected()) {
        $this->loaded = true;
        $this->getSceneHelper()->setNewScene("Hub"); // once done loading data we can put the player into the hub scene
      }
    });
  }

  // save player data (right now is only settings, this later would be Elo, kits, and so on)
  public function saveData(): void
  {
    if ($this->loaded) {
      $this->settings?->saveSettings();
    }
  }

  // below are helper functions for managing a player's scoreboards and other data like inventory and child entities

  public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null): bool
  {
    if (SwimCore::$DEBUG) {
      echo("Teleport on " . $this->getName() . " called\n");
      StackTracer::PrintStackTrace();
    }
    if (!$this->isConnected()) return false; // this could maybe cause horrible bugs, but it shouldn't in theory

    // log this for ghost player fixes (anti cheat could also use this maybe for something)
    $this->previousPositionBeforeTeleport = clone($this->getPosition());

    // we have some specific actions to do on teleport such as avoiding false flags for things
    $this->antiCheatData?->teleported();
    $this->ticksSinceLastTeleport = 0;

    // do it if changing worlds
    $do = false;
    if ($pos instanceof Position) {
      $do = $this->previousPositionBeforeTeleport->world !== $pos->world;
    }

    // game mode check as well, hopefully this doesn't screw anything up
    if ($this->gamemode == GameMode::SPECTATOR || $do) {
      $this->ghostPlayerFix();
    }

    $r = parent::teleport($pos, $yaw, $pitch);
    if ($r) $this->betterBlockBreaker = null;

    return $r;
  }

  /**
   * @brief Call this when teleporting to hub or leaving the game.
   * This is to despawn the player entity from the world to the other clients to get rid of "ghost" players due to a multi world bug.
   * The ghost players wil still flash on the screen for a tick or 2 visually on any direct viewing clients but this is better than random floating ghosts.
   * @return void
   */
  public function ghostPlayerFix(): void
  {
    if (!$this->previousPositionBeforeTeleport) return;

    if (SwimCore::$DEBUG) {
      $posStr = PositionHelper::toString($this->previousPositionBeforeTeleport);
      $worldStr = $this->previousPositionBeforeTeleport->getWorld()->getFolderName();
      echo("{$this->getName()} | Despawn called at {$posStr} | {$worldStr}\n");
    }

    // I assume world position viewers is good enough? This gets the players who had that player entity in their loaded chunks client side.
    // However, I don't know if this ghost player bug was an issue for players who had them outside their loaded chunks.
    // What I mean by this is players could get ghost player bugged, then another client comes in and loads those chunks and sees the ghost.
    // $players = $this->previousPositionBeforeTeleport->getWorld()->getViewersForPosition($this->previousPositionBeforeTeleport);
    $players = $this->previousPositionBeforeTeleport->getWorld()->getPlayers(); // doing all players to be safe
    foreach ($players as $player) {
      if ($player !== $this && $this->id !== $player->getId()) {
        $player->getNetworkSession()->sendDataPacket(RemoveActorPacket::create($this->id), true);
        if (SwimCore::$DEBUG) echo("Despawning {$this->getName()} from {$player->getName()}\n}");
      }
    }
  }

  public function attack(EntityDamageEvent $source): void
  {
    parent::attack($source);
    if (!$source->isCancelled()) $this->antiCheatData?->attacked();
  }


  public function attackBlock(Vector3 $pos, int $face): bool
  {
    if ($pos->distanceSquared($this->location) > 10000) {
      return false;
    }

    $target = $this->getWorld()->getBlock($pos);

    $ev = new PlayerInteractEvent($this, $this->inventory->getItemInHand(), $target, null, $face, PlayerInteractEvent::LEFT_CLICK_BLOCK);
    if ($this->isSpectator()) {
      $ev->cancel();
    }

    $ev->call();
    if ($ev->isCancelled()) {
      return false;
    }

    $this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
    if ($target->onAttack($this->inventory->getItemInHand(), $face, $this)) {
      return true;
    }

    $block = $target->getSide($face);
    if ($block->hasTypeTag(BlockTypeTags::FIRE)) {
      $this->getWorld()->setBlock($block->getPosition(), VanillaBlocks::AIR());
      $this->getWorld()->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new FireExtinguishSound());
      return true;
    }

    if (!$this->isCreative()) {
      $this->betterBlockBreaker = new BetterBlockBreaker($this, $pos, $target, $face, 16);
    }

    return true;
  }


  public function breakBlock(Vector3 $pos): bool
  {

    if (!isset($this->antiCheatData)) return false; // if anti cheat isn't ready then don't break blocks
    if ($this->antiCheatData->nukeCheck()) return false; // the block break is cancelled if nuke check returns true

    if ($this->isCreative()) {
      return parent::breakBlock($pos);
    }

    if (!isset($this->betterBlockBreaker)) {
      return false;
    }

    $breakHandler = $this->betterBlockBreaker;
    if ($breakHandler->getNextBreakProgress(2) >= 0.97) {
      return parent::breakBlock($pos);
    } else {
      $this->betterBlockBreaker->setClientAttemptedTooEarly();
      return false;
    }
  }

  public function continueBreakBlock(Vector3 $pos, int $face): void
  {
    if ($this->betterBlockBreaker !== null && $this->betterBlockBreaker->getBlockPos()->distanceSquared($pos) < 0.0001) {
      $this->betterBlockBreaker->setTargetedFace($face);
    }
  }

  public function stopBreakBlock(Vector3 $pos): void
  {
    if ($this->betterBlockBreaker !== null && $this->betterBlockBreaker->getBlockPos()->distanceSquared($pos) < 0.0001) {
      $this->betterBlockBreaker = null;
    }
  }

  public function getPitchTowards(Vector3 $target): float
  {
    $horizontal = sqrt(($target->x - $this->location->x) ** 2 + ($target->z - $this->location->z) ** 2);
    $vertical = $target->y - ($this->location->y + $this->getEyeHeight());
    return -atan2($vertical, $horizontal) / M_PI * 180; //negative is up, positive is down
  }

  public function getYawTowards(Vector3 $target): float
  {
    $xDist = $target->x - $this->location->x;
    $zDist = $target->z - $this->location->z;

    $yaw = atan2($zDist, $xDist) / M_PI * 180 - 90;
    if ($yaw < 0) {
      $yaw += 360.0;
    }

    return $yaw;
  }

  public function handleDiscordLinkRequest(string $discordName, string $discordId): void
  {
    /*
    if ($this->linkHandler->getDiscordId() !== null) {
      $this->sendMessage(TextFormat::RED . "A Discord link request was found, but you are already linked. If you want to accept the link, remove your current link forst with /discord remove. To deny the link request, run /discord deny. If someone else tried to link to your account, please report them to us.");
      return;
    }
    $this->sendMessage(TextFormat::GREEN . "You have a Discord link request from $discordName. Run /discord accept to accept or /discord deny to deny.");
    $this->linkHandler->setPendingLink($discordId);
    */
  }

  public function getTicksSinceLastTeleport(): int
  {
    return $this->ticksSinceLastTeleport;
  }

  public function setGamemode(GameMode $gm): bool
  {
    $value = parent::setGamemode($gm);

    // tell the anti cheat we changed game mode, so we can handle when needed
    $this->antiCheatData?->changedGameMode();

    return $value;
  }

  public function isInScene(string $sceneName): bool
  {
    return $sceneName === $this->sceneHelper?->getScene()?->getSceneName();
  }

  public function interactBlock(Vector3 $pos, int $face, Vector3 $clickOffset): bool
  {
    if (!isset($this->antiCheatData)) return false; // if anti cheat isn't ready then don't break blocks

    // checks if we tried placing a block
    if ($this->inventory->getItemInHand()->getBlock()->getTypeId() != BlockTypeIds::AIR) {
      $somethingHappened = parent::interactBlock($pos, $face, $clickOffset);
      $this->antiCheatData->lastBlockPlaceTick = $this->core->getServer()->getTick(); // update last attempted block place time
      // if ($somethingHappened) $this->antiCheatData->blockPlaceCheck(); // if something happened, we need to call the block place check
      return $somethingHappened;
    }

    return parent::interactBlock($pos, $face, $clickOffset); // otherwise we just interacted on the block normally
  }

  // artificial is false by default in the event that a natural pocketmine function calls it, such as when dealing knock back from damage events
  public function setMotion(Vector3 $motion, bool $artificial = false): bool
  {
    // $this->antiCheatData?->getMotion()->setSleepTicks((int)(20 * $motion->length())); // 1 second of ticks per length unit
    if ($artificial) $this->ticksSinceLastMotionSet = 0;
    return parent::setMotion($motion);
  }

  /* commented out because we don't have the motion detection in swimcore public (or any detections for that matter)
  public function addMotion(float $x, float $y, float $z): void
  {
    $this->antiCheatData?->getMotion()->setSleepTicks((int)(20 * (new Vector3($x, $y, $z))->length())); // 1 second of ticks per length unit
    parent::addMotion($x, $y, $z);
  }
  */

  /**
   * @throws ScoreFactoryException
   * Quick helper for refreshing the scoreboard
   */
  public function refreshScoreboard($scoreboardTitle): void
  {
    $this->removeScoreboard();
    ScoreFactory::setObjective($this, $scoreboardTitle);
  }

  /**
   * @throws ScoreFactoryException
   * handles if the scoreboard setting is enabled, while also handling removing the scoreboard if it is not enabled
   */
  public function isScoreboardEnabled(): bool
  {
    $enabled = $this->settings->getToggle('showScoreboard');
    if (!$enabled) {
      $this->removeScoreboard();
    }
    return $enabled;
  }

  /**
   * @throws ScoreFactoryException
   * Delete a player's scoreboard
   */
  public function removeScoreboard(): void
  {
    if (ScoreFactory::hasObjective($this)) {
      ScoreFactory::removeObjective($this);
    }
  }

  // deletes all child entities of the player, such as projectiles they have thrown
  public function removeChildEntities(): void
  {
    $worlds = $this->core->getServer()->getWorldManager()->getWorlds();
    foreach ($worlds as $world) {
      $entities = $world->getEntities();
      foreach ($entities as $entity) {
        if ($entity instanceof Entity && $entity->getOwningEntity() === $this) {
          $entity->kill();
        }
      }
    }
  }

  public function bossBar(string $title, float $healthPercent, bool $darkenScreen = false, int $color = BossBarColor::PURPLE, int $overlay = 0): void
  {
    $packet = BossEventPacket::show($this->id, $title, $healthPercent, $darkenScreen, $color, $overlay);
    $this->getNetworkSession()->sendDataPacket($packet);
  }

  public function removeBossBar(): void
  {
    $packet = BossEventPacket::hide($this->id);
    $this->getNetworkSession()->sendDataPacket($packet);
  }

  // default name tag handling for showing rank or the nick if there is one
  public function genericNameTagHandling(): void
  {
    if ($this->nicks->isNicked()) {
      $this->setNameTag(TextFormat::GRAY . $this->nicks->getNick());
    } else {
      $this->rank->rankNameTag();
    }
  }

  /**
   * @throws ScoreFactoryException
   * Some stuff we do have to do manually, like removing scoreboard and inventory
   * All of these options can be manually switched off to not do a full clearance via parameters
   */
  public function cleanPlayerState
  (
    bool $clearComponents = true,
    bool $clearBehaviors = true,
    bool $clearInventory = true,
    bool $clearScoreBoard = true,
    bool $clearTags = true,
    bool $clearBossBar = true,
  ): void
  {
    if (!$this->isConnected()) return;

    // clear inventory
    if ($clearInventory) {
      InventoryUtil::fullPlayerReset($this);
    }

    // remove scoreboard
    if ($clearScoreBoard) {
      $this->removeScoreboard();
    }

    // clear all components
    if ($clearComponents) {
      foreach ($this->components as $component) {
        $component->clear();
      }
    }

    // do the same for the event behavior components
    if ($clearBehaviors) {
      $this->eventBehaviorComponentManager->clear();
    }

    // remove score tag and set the name tag back to the player's name
    if ($clearTags) {
      $this->setScoreTag(""); // setting it to an empty string hides it
      $this->setNameTag($this->getName());
    }

    // remove the boss bar
    if ($clearBossBar) {
      $this->removeBossBar();
    }
  }

  /**
   * @throws ReflectionException
   */
  public function transfer(string $address, int $port = 19132, string|Translatable|null $message = null): bool
  {
    if (SwimCore::$isNetherGames) {
      (new ReflectionClass(NetworkSession::class))->getProperty("chunkCacheBlobs")->setValue($this->getNetworkSession(), []);
    }
    return parent::transfer($address, $port, $message);
  }

  // below are getters for each component

  public function getChatHandler(): ?ChatHandler
  {
    return $this->chatHandler;
  }

  public function getClickHandler(): ?ClickHandler
  {
    return $this->clickHandler;
  }

  public function getCoolDowns(): ?CoolDowns
  {
    return $this->coolDowns;
  }

  public function getInvites(): ?Invites
  {
    return $this->invites;
  }

  public function getNicks(): ?Nicks
  {
    return $this->nicks;
  }

  public function getRank(): ?Rank
  {
    return $this->rank;
  }

  public function getSceneHelper(): ?SceneHelper
  {
    return $this->sceneHelper;
  }

  public function getSettings(): ?Settings
  {
    return $this->settings;
  }

  public function getAntiCheatData(): ?AntiCheatData
  {
    return $this->antiCheatData;
  }

  public function getNslHandler(): ?NetworkStackLatencyHandler
  {
    return $this->nslHandler;
  }

  public function getAttributes(): ?Attributes
  {
    return $this->attributes;
  }

  public function getCombatLogger(): ?CombatLogger
  {
    return $this->combatLogger;
  }

  public function getAckHandler(): ?AckHandler
  {
    return $this->ackHandler;
  }

  public function getCosmetics(): ?Cosmetics
  {
    return $this->cosmetics;
  }

  public function getExactPosition(): Vector3
  {
    return $this->exactPosition;
  }

  public function setExactPosition(Vector3 $vector3): void
  {
    $this->exactPosition = $vector3;
  }

  // override to force public
  public function setPosition(Vector3 $pos): bool
  {
    return parent::setPosition($pos);
  }

  public function getTicksSinceMotionArtificiallySet(): int
  {
    return $this->ticksSinceLastMotionSet;
  }

  // we have to do this for super weird stuff like explosion caused damage events
  public function resetTicksSinceMotionArtificiallySet(): void
  {
    $this->ticksSinceLastMotionSet = 0;
  }

  public function getComponents(): array
  {
    return $this->components;
  }

}