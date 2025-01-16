<?php

namespace core\listeners;

use core\SwimCore;
use core\systems\player\components\ClickHandler;
use core\systems\player\PlayerSystem;
use core\systems\player\SwimPlayer;
use core\systems\SystemManager;
use core\utils\AcData;
use core\utils\acktypes\EntityPositionAck;
use core\utils\acktypes\EntityRemovalAck;
use core\utils\acktypes\KnockbackAck;
use core\utils\raklib\StubLogger;
use core\utils\raklib\SwimNetworkSession;
use Exception;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\block\BlockMeltEvent;
use pocketmine\event\block\LeavesDecayEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\ConsumableItem;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\BlockEventPacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MoveActorDeltaPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\ServerboundDiagnosticsPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ServerSettingsRequestPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\command\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\format\io\BaseWorldProvider;
use pocketmine\world\World;
use ReflectionClass;
use ReflectionException;

// this listener is just a bunch of tweaks to make global vanilla events better

class WorldListener implements Listener
{

  private SwimCore $core;
  private PlayerSystem $playerSystem;
  private SystemManager $systemManager;

  private array $eduItemIds = []; // education item crap to filter out from the client
  private array $armorSounds = [];

  public function __construct(SwimCore $core)
  {
    $this->core = $core;
    $this->systemManager = $this->core->getSystemManager();
    $this->playerSystem = $this->systemManager->getPlayerSystem();
    $this->cacheArmorSounds();
  }

  public function cacheArmorSounds(): void
  {
    $refl = new ReflectionClass(LevelSoundEvent::class);
    foreach ($refl->getConstants() as $name => $value) {
      if (str_starts_with($name, "ARMOR_EQUIP")) {
        $this->armorSounds[] = $value;
      }
    }
  }

  /**
   * @throws ReflectionException
   */
  public function onWorldLoad(WorldLoadEvent $event): void
  {
    self::disableWorldLogging($event->getWorld());
  }

  /**
   * @throws ReflectionException
   */
  public static function disableWorldLogging(World $world): void
  {
    $provider = $world->getProvider();
    (new ReflectionClass(BaseWorldProvider::class))->getProperty("logger")->setValue($provider, new StubLogger());
    echo("Disabling world logging on world | Display: {$world->getDisplayName()} | Folder: {$world->getFolderName()}\n");
  }

  // void can't kill unless we are really low
  public function onEntityVoid(EntityDamageEvent $event)
  {
    if ($event->getCause() == EntityDamageEvent::CAUSE_VOID) {
      $entity = $event->getEntity();
      if ($entity->getPosition()->getY() > -200) {
        $event->cancel();
      }
    }
  }

  public function onLeavesDecay(LeavesDecayEvent $event)
  {
    $event->cancel();
  }

  public function onGrow(BlockGrowEvent $event): void
  {
    $event->cancel();
  }

  public function onBurn(BlockBurnEvent $event): void
  {
    $event->cancel();
  }

  public function onMelt(BlockMeltEvent $event): void
  {
    $event->cancel();
  }

  // only cancels door opens in the hub
  public function hubBlockInteract(PlayerInteractEvent $event)
  {
    $player = $event->getPlayer();
    if (!$player->isCreative() && $player->getWorld()->getFolderName() === "hub") {
      $blockName = strtolower($event->getBlock()->getName());
      if (str_contains($blockName, "door") || str_contains($blockName, "gate")) {
        $event->cancel();
      }
    }
  }

  // remove offhand functionality
  public function preventOffHanding(InventoryTransactionEvent $event)
  {
    $inventories = $event->getTransaction()->getInventories();
    foreach ($inventories as $inventory) {
      if ($inventory instanceof PlayerOffHandInventory) {
        $event->cancel();
      }
    }
  }

  // disable sending the chemistry pack to players on joining so particles look fine
  public function onDataPacketSendEvent(DataPacketSendEvent $event): void
  {
    // UNCOMMENT OUT THE BLOCKED COMMENTS IF YOU ARE USING MULTI VERSION (nether games, swim services fork, etc.)
    /*
    $protocol = ProtocolInfo::CURRENT_PROTOCOL;
    if (isset($event->getTargets()[0])) {
      $protocol = $event->getTargets()[0]->getProtocolId();
    }
    */

    $packets = $event->getPackets();
    foreach ($packets as $packet) {
      if ($packet instanceof ResourcePackStackPacket) {
        $stack = $packet->resourcePackStack;
        foreach ($stack as $key => $pack) {
          if ($pack->getPackId() === "0fba4063-dba1-4281-9b89-ff9390653530") {
            unset($packet->resourcePackStack[$key]);
            break;
          }
        }
        // experiment resource pack
        /*
        if ($protocol == 671) {
          $packet->experiments = new Experiments(["updateAnnouncedLive2023" => true], true);
          $stack[] = new ResourcePackStackEntry("d8989e4d-5217-4d57-a6f6-1787c620be97", "0.0.1", "");
        }
        */
        break;
      }
    }
  }

  // rod sound
  /* leave this to the custom rod class to implement
  public function rodCastSound(PlayerItemUseEvent $event)
  {
    $player = $event->getPlayer();
    $item = $event->getItem();
    if ($item->getName() == "Fishing Rod") {
      ServerSounds::playSoundToPlayer($player, "random.bow", 2, 1);
    }
  }
  */

  // prevent player drops (be mindful of this event's existence if we are ever programming a game where we want entity drops to go somewhere like a chest)
  public function onPlayerDeath(PlayerDeathEvent $event)
  {
    $event->setDrops([]);
    $event->setXpDropAmount(0);
  }

  // prevent switch hits
  /* we already do this in player listener
  public function onEntityDamagedByEntity(EntityDamageByEntityEvent $event)
  {
    if ($event->getModifier(EntityDamageEvent::MODIFIER_PREVIOUS_DAMAGE_COOLDOWN) < 0) {
      $event->cancel();
    }
  }
  */

  // never have exhaust
  public function onExhaust(PlayerExhaustEvent $event)
  {
    $event->cancel();
  }

  // cancel swimming animation (don't think this works)
  /*
  public function onSwim(PlayerToggleSwimEvent $event)
  {
    $event->cancel();
  }
  */

  // cancel weird drops
  public function onBlockBreak(BlockBreakEvent $event)
  {
    $id = $event->getBlock()->getTypeId();
    if ($id == BlockTypeIds::TALL_GRASS
      || $id == BlockTypeIds::DOUBLE_TALLGRASS
      || $id == BlockTypeIds::SUNFLOWER
      || $id == BlockTypeIds::COBWEB
      || $id == BlockTypeIds::LARGE_FERN) {
      $event->setDrops([]);
    }
  }

  // does a swing animation fix than an ID based switch statement for doing specific things related to packet receiving
  // The most crucial thing by far is calling the recv() method on nsl receive
  public function onDataPacketReceive(DataPacketReceiveEvent $event): void
  {
    $packet = $event->getPacket();
    $player = $event->getOrigin()->getPlayer();
    if ($player) {
      /** @var SwimPlayer $player */
      $this->swingAnimationFix($event, $packet, $player);
      $this->handleReceive($packet, $player, $event);
      $this->handleInput($event, $player); // update player info based on input
      $this->processSwing($event, $player); // when player swings there fist (left click)
    }
  }

  private function swingAnimationFix(DataPacketReceiveEvent $event, ServerboundPacket $packet, Player $player): void
  {
    if ($packet->pid() == LevelSoundEventPacket::NETWORK_ID) {
      /** @var LevelSoundEventPacket $packet */
      if ($packet->sound == LevelSoundEvent::ATTACK_NODAMAGE) {
        $player->broadcastAnimation(new ArmSwingAnimation($player), $player->getViewers());
        $event->cancel(); // cancel to remove sound I guess
      }
    }
  }

  /**
   * this code is a god awful mess, but does what is needed for the time being, a lot of commented out code due to porting this from Divinity
   */
  private function handleReceive(ServerboundPacket $packet, SwimPlayer $player, DataPacketReceiveEvent $event): void
  {
    switch ($packet->pid()) {
      case InventoryTransactionPacket::NETWORK_ID:
        /** @var InventoryTransactionPacket $packet */
        if ($packet->trData instanceof UseItemTransactionData && $packet->trData->getActionType() === UseItemTransactionData::ACTION_BREAK_BLOCK
          && ($player->getGamemode() === GameMode::SURVIVAL || $player->getGamemode() === GameMode::ADVENTURE)) {
          $event->cancel();
        }
        /*
        if ($packet->trData instanceof UseItemTransactionData && $packet->trData->getActionType() === UseItemTransactionData::ACTION_CLICK_BLOCK
          && $player->getSettings()->seasonalEffects && $player->getSceneClass()?->getSnowy()) {
          $event->cancel();
        }
        */
        break;

      case ActorEventPacket::NETWORK_ID:
        /** @var ActorEventPacket $packet */
        if ($packet->eventId != ActorEvent::EATING_ITEM) return;

        if (!$player->getInventory()->getItemInHand() instanceof ConsumableItem) {
          $event->cancel();
          if ($player->getInventory()->getItemInHand()->getTypeId() == VanillaBlocks::AIR()->asItem()->getTypeId())
            return;

          if ($packet->actorRuntimeId != $player->getId())
            print ("ID mismatch\n");
        }
        break;

      case NetworkStackLatencyPacket::NETWORK_ID:
        /** @var NetworkStackLatencyPacket $packet */
        $player->getAckHandler()?->recv($packet); // this is CRUCIAL
        return;

      case RequestChunkRadiusPacket::NETWORK_ID:
        /*
        if (isset($packet->maxRadius) && $this->divinityCore->acOn) {
          $this->core->getAcUtils()->checkGophertunnel($player, $packet);
        }
        */
        break;
      case ServerboundDiagnosticsPacket::NETWORK_ID:
        /** @var ServerboundDiagnosticsPacket $packet */
        // $player->setFps((int) $packet->getAvgFps()); // we don't have a use for this yet
        break;
      case CommandRequestPacket::NETWORK_ID:
        /** @var CommandRequestPacket $packet */
        if ($packet->originData->type !== CommandOriginData::ORIGIN_PLAYER) {
          $event->cancel();
          return;
        }
        break;
      case ServerSettingsRequestPacket::NETWORK_ID:
        // (new SettingsForm($this->divinityCore))->settingsForm($player, true); // psuedo from divinity's code base
        break;
      case InteractPacket::NETWORK_ID:
        /** @var InteractPacket $packet */
        if ($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE) {
          /* TODO FOR LATER ONCE RIDEABLE MOBS ARE IMPLEMENTED
          if ($packet->targetActorRuntimeId === $player->getRidingEntityId()) {
            $player->removeRidingEntity();
          }
          */
        }
        break;
    }
  }

  /**
   * @throws ReflectionException
   * @throws Exception
   */
  public function onDataPacketSend(DataPacketSendEvent $event): void
  {
    $packets = $event->getPackets();

    foreach ($packets as $key => $packet) {
      switch ($packet->pid()) {
        case CraftingDataPacket::NETWORK_ID:
          // $this->handleCraftingPacket($packet, $packets, $key); // I couldn't figure this out for only allowing certain recipes
          break;

        case SetTimePacket::NETWORK_ID:
          $this->handleSetTimePacket($packet, $packets, $key);
          break;

        case PlayerListPacket::NETWORK_ID:
          $this->handlePlayerListPacket($packet);
          break;

        case StartGamePacket::NETWORK_ID:
          $this->handleStartGamePacket($packet, $event, $key);
          break;

        case CreativeContentPacket::NETWORK_ID:
          $this->handleCreativeContentPacket($packet, $event, $key);
          break;

        case SetPlayerGameTypePacket::NETWORK_ID:
          $this->handleSetPlayerGameTypePacket($packet, $event, $key);
          break;

        case LevelSoundEventPacket::NETWORK_ID:
          $this->handleLevelSoundEventPacket($packet, $packets, $key);
          break;

        case BlockEventPacket::NETWORK_ID:
          $this->handleBlockEventPacket($packet, $packets, $key);
          break;

        case MoveActorAbsolutePacket::NETWORK_ID:
        case AddActorPacket::NETWORK_ID:
        case AddPlayerPacket::NETWORK_ID:
          $this->handleActorPackets($packet, $packets, $key, $event);
          break;

        case RemoveActorPacket::NETWORK_ID:
          $this->handleRemoveActorPacket($packet, $event);
          break;

        case SetActorMotionPacket::NETWORK_ID:
          $this->handleSetActorMotionPacket($packet, $event);
          break;

        default:
          // Optionally handle other packets or do nothing
          break;
      }
    }

    $event->setPackets($packets);
  }

  // disables crafting completely, intended for 1.21 at the moment
  private function processCraftingPacket($packet, &$packets, $key): bool
  {
    if ($packet instanceof CraftingDataPacket) {
      unset($packets[$key]);
      return true;
    }
    return false;
  }

  /**
   * Handles the SetTimePacket.
   */
  private function handleSetTimePacket($packet, &$packets, $key): void
  {
    /** @var SetTimePacket $packet */
    if ($packet->time >= 2000000000) {
      $packet->time -= 2000000000;
    } else {
      unset($packets[$key]);
    }
  }

  /**
   * Handles the PlayerListPacket.
   */
  private function handlePlayerListPacket($packet): void
  {
    /** @var PlayerListPacket $packet */
    foreach ($packet->entries as $entry) {
      $entry->xboxUserId = "";
    }
  }

  /**
   * Handles the StartGamePacket.
   */
  private function handleStartGamePacket($packet, $event, $key): void
  {
    /** @var StartGamePacket $packet */
    for ($i = 0; $i < count($packet->itemTable); $i++) {
      if (str_contains($packet->itemTable[$i]->getStringId(), "element") ||
        str_contains($packet->itemTable[$i]->getStringId(), "chemistry")) {
        $playerName = $event->getTargets()[$key]->getPlayer()->getName() ?? "null";
        $this->eduItemIds[$playerName][] = $packet->itemTable[$i]->getNumericId();
        unset($packet->itemTable[$i]);
      }
    }

    $packet->levelSettings->gameRules["dodaylightcycle"] = new BoolGameRule(false, false);
    $packet->levelSettings->time = World::TIME_DAY;

    $experiments = ["deferred_technical_preview" => true];

    $protocol = ProtocolInfo::CURRENT_PROTOCOL;
    if (isset($event->getTargets()[0])) {
      $protocol = $event->getTargets()[0]->getProtocolId();
    }

    if ($protocol == 671) {
      $experiments["updateAnnouncedLive2023"] = true;
    }

    $packet->levelSettings->experiments = new Experiments($experiments, true);
  }

  /**
   * Handles the CreativeContentPacket.
   * @throws ReflectionException
   */
  private function handleCreativeContentPacket($packet, $event, $key): void
  {
    /** @var CreativeContentPacket $packet */
    $entries = $packet->getEntries();
    for ($i = 0; $i < count($entries); $i++) {
      if (isset($entries[$i]) && in_array($entries[$i]->getItem()->getId(),
          $this->eduItemIds[$event->getTargets()[$key]->getPlayer()->getName() ?? "null"])) {
        unset($entries[$i]);
      }
    }

    $reflection = new ReflectionClass($packet);
    $property = $reflection->getProperty("entries");
    $property->setAccessible(true); // not sure why phpstorm throws a fit over this
    $property->setValue($packet, $entries);
  }

  /**
   * Handles the SetPlayerGameTypePacket.
   */
  private function handleSetPlayerGameTypePacket($packet, $event, $key): void
  {
    /** @var SetPlayerGameTypePacket $packet */
    if ($packet->gamemode == GameMode::CREATIVE && count($event->getTargets()) > 0) {
      $firstTarget = array_values($event->getTargets())[$key] ?? null;
      if ($firstTarget) {
        $player = $firstTarget->getPlayer();
        if ($player !== null && $player->getGamemode() === GameMode::SPECTATOR) {
          // Assuming 6 corresponds to spectator or something, not sure, this might be for divinity setting custom types where you can see your own head
          $packet->gamemode = 6;
        }
      }
    }
  }

  /**
   * Handles the LevelSoundEventPacket.
   */
  private function handleLevelSoundEventPacket($packet, &$packets, $key): void
  {
    /** @var LevelSoundEventPacket $packet */
    $suppressSounds = [
      LevelSoundEvent::ATTACK_NODAMAGE,
      LevelSoundEvent::HIT,
      LevelSoundEvent::CHEST_CLOSED,
      LevelSoundEvent::CHEST_OPEN,
      LevelSoundEvent::ENDERCHEST_OPEN,
      LevelSoundEvent::ENDERCHEST_CLOSED
    ];

    if (in_array($packet->sound, $suppressSounds, true) || in_array($packet->sound, $this->armorSounds, true)) {
      unset($packets[$key]);
    }
  }

  /**
   * Handles the BlockEventPacket.
   */
  private function handleBlockEventPacket($packet, &$packets, $key): void
  {
    /** @var BlockEventPacket $packet */
    if ($packet->eventType == 1) {
      unset($packets[$key]);
    }
  }

  /**
   * Handles MoveActorAbsolutePacket, AddActorPacket, and AddPlayerPacket.
   */
  private function handleActorPackets($packet, &$packets, $key, $event): void
  {
    /** @var MoveActorAbsolutePacket|AddActorPacket|AddPlayerPacket $packet */
    $entity = $this->core->getServer()->getWorldManager()->findEntity($packet->actorRuntimeId);
    if ($entity instanceof SwimPlayer) {
      $tp = false;
      if (isset($packet->flags)) {
        $tp = ($packet->flags & MoveActorAbsolutePacket::FLAG_TELEPORT) > 0;
      }
      foreach ($event->getTargets() as $target) {
        if ($target instanceof SwimNetworkSession) {
          $target->addToNslBuffer(new EntityPositionAck($packet->position, $packet->actorRuntimeId, $tp));
        }
      }
    }

    // Special handling for AddActorPacket with FIREBALL type
    /*
    if ($packet->pid() === AddActorPacket::NETWORK_ID && $packet->type === EntityIds::FIREBALL) {
      if (isset($event->getTargets()[0])) {
        $player = $event->getTargets()[0]->getPlayer();
        if ($player !== null && $player->getSettings()->dragonFireball) {
          $packet->type = EntityIds::DRAGON_FIREBALL;
        }
      }
    }
    */

    // Handle delta updates if enabled
    if ($this->core->deltaOn && $entity !== null && isset($entity->supportsDelta)) {
      if ($packet->pid() === AddPlayerPacket::NETWORK_ID || $packet->pid() === AddActorPacket::NETWORK_ID) {
        $pos = $entity->getOffsetPosition($packet->position);
        $packets[] = MoveActorAbsolutePacket::create(
          $packet->actorRuntimeId,
          $pos,
          $packet->pitch,
          $packet->yaw,
          $packet->headYaw,
          0
        );
      }
      if ($packet->pid() === MoveActorAbsolutePacket::NETWORK_ID) {
        /** @var MoveActorAbsolutePacket $packet */
        $lastLocation = clone $entity->getPrevPos();
        $lastLocation->y = $entity->getOffsetPosition($lastLocation)->getY();
        $currentLocation = Location::fromObject($packet->position, null, $packet->yaw, $packet->pitch);

        $pk = new MoveActorDeltaPacket();
        $pk->actorRuntimeId = $packet->actorRuntimeId;
        $pk->flags = 0;

        if (($packet->flags & MoveActorAbsolutePacket::FLAG_GROUND) > 0) {
          $pk->flags |= MoveActorDeltaPacket::FLAG_GROUND;
        }

        if ($lastLocation->x !== $currentLocation->x) {
          $pk->xPos = $currentLocation->x;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_X;
        }
        if ($lastLocation->y !== $currentLocation->y) {
          $pk->yPos = $currentLocation->y;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_Y;
        }
        if ($lastLocation->z !== $currentLocation->z) {
          $pk->zPos = $currentLocation->z;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_Z;
        }
        if ($lastLocation->pitch !== $currentLocation->pitch) {
          $pk->pitch = $currentLocation->pitch;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_PITCH;
        }
        if ($lastLocation->yaw !== $currentLocation->yaw) {
          $pk->yaw = $currentLocation->yaw;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_YAW;
          $pk->headYaw = $currentLocation->yaw;
          $pk->flags |= MoveActorDeltaPacket::FLAG_HAS_HEAD_YAW;
        }
        $packets[$key] = $pk;
      }
    }

    // Existing processing for Actor Data Packets
    if ($packet->pid() == SetActorDataPacket::NETWORK_ID || $packet->pid() == AddActorPacket::NETWORK_ID || $packet->pid() == AddPlayerPacket::NETWORK_ID) {
      if (isset($event->getTargets()[0]) && count($event->getTargets()) == 1) {
        $target = $event->getTargets()[0];
        $player = $target->getPlayer();
        $swimPlayer = $this->playerSystem->getSwimPlayer($player);
        if ($swimPlayer) {
          if (!$swimPlayer->getSettings()->getToggle('showScoreTags')) {
            $packet->metadata[EntityMetadataProperties::SCORE_TAG] = new StringMetadataProperty("");
          } else if (!isset($packet->metadata[EntityMetadataProperties::SCORE_TAG])) {
            foreach ($this->core->getServer()->getOnlinePlayers() as $pl) {
              if ($pl->getId() == $packet->actorRuntimeId) {
                $packet->metadata[EntityMetadataProperties::SCORE_TAG] = new StringMetadataProperty($pl->getScoreTag());
                break;
              }
            }
          }
        }
      } else {
        foreach ($event->getTargets() as $target) {
          $target->sendDataPacket(clone($packet));
        }
        unset($packets[$key]);
      }
    }
  }

  /**
   * Handles the RemoveActorPacket.
   */
  private function handleRemoveActorPacket($packet, $event): void
  {
    /** @var RemoveActorPacket $packet */
    foreach ($event->getTargets() as $target) {
      if ($target instanceof SwimNetworkSession) {
        $target->addToNslBuffer(new EntityRemovalAck($packet->actorUniqueId));
      }
    }
  }

  /**
   * Handles the SetActorMotionPacket.
   */
  private function handleSetActorMotionPacket($packet, $event): void
  {
    /** @var SetActorMotionPacket $packet */
    foreach ($event->getTargets() as $target) {
      $pl = $target->getPlayer();
      if ($pl->getId() != $packet->actorRuntimeId) {
        continue;
      }
      if ($target instanceof SwimNetworkSession) {
        $target->addToNslBuffer(new KnockbackAck($packet->motion));
      }
    }
  }

  private function handleInput(DataPacketReceiveEvent $event, SwimPlayer $swimPlayer): void
  {
    $packet = $event->getPacket();
    if (!($packet instanceof PlayerAuthInputPacket)) return;

    $swimPlayer->setExactPosition($packet->getPosition()->subtract(0, 1.62, 0)); // I don't know what the point of exact position is, something from GameParrot

    // auto sprint
    $settings = $swimPlayer->getSettings();
    if ($settings) {
      if ($settings->isAutoSprint()) {
        if ($packet->getMoveVecZ() > 0.5) {
          $swimPlayer->setSprinting();
        } else {
          $swimPlayer->setSprinting(false);
        }
      }
    }
  }

  private int $threshold = 45; // in MS, this is supposed to 50 but that cancels way too much CPS

  private static string $spacer = TF::GRAY . " | " . TF::RED;

  private function processSwing(DataPacketReceiveEvent $event, SwimPlayer $swimPlayer): void
  {
    $packet = $event->getPacket();
    $swung = false;

    if ($packet instanceof PlayerAuthInputPacket) {
      // $swung = (($packet->getInputFlags() & (1 << PlayerAuthInputFlags::MISSED_SWING)) !== 0);
      // 1.21.50: Instead of performing a bitwise & on a BitSet, call get() with the index
      $swung = $packet->getInputFlags()->get(PlayerAuthInputFlags::MISSED_SWING);
    }

    if ($packet instanceof LevelSoundEventPacket) {
      $swung = $packet->sound == LevelSoundEvent::ATTACK_NODAMAGE;
    }

    if ($swung || ($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData)) {
      $ch = $swimPlayer->getClickHandler();
      if ($ch) {

        $isRanked = $swimPlayer->getSceneHelper()?->getScene()->isRanked() ?? false;

        // dc prevent logic if enabled or in a ranked scene
        $settings = $swimPlayer->getSettings();
        if ($isRanked || ($settings?->dcPreventOn())) {
          if (((microtime(true) * 1000) - ($swimPlayer->getAntiCheatData()->getData(AcData::LAST_CLICK_TIME) ?? 0)) < $this->threshold) {
            $event->cancel(); // block the swing
          } else {
            $swimPlayer->getAntiCheatData()->setData(AcData::LAST_CLICK_TIME, microtime(true) * 1000);
          }
        }

        // if dc prevent didn't cancel the click then we can call it
        if (!$event->isCancelled()) {
          $ch->click();
        }

        // only does this notification in ranked marked scenes
        if ($isRanked && $ch->getCPS() > ClickHandler::CPS_MAX) {
          $msg = TF::RED . "Clicked above " . TF::YELLOW . ClickHandler::CPS_MAX . TF::RED . " CPS" . self::$spacer . TF::YELLOW . "Attacks will deal Less KB";
          $swimPlayer->sendActionBarMessage($msg);
        }
      }
    }
  }

  /* Region and cross server query stuff is not in SwimCore public release, but leaving this commented out to show how to do this in a psuedo way.
  public function onQueryRegenerate(QueryRegenerateEvent $ev)
  {
    if (!$this->core->getRegionInfo()->isHub) return;
    $count = $this->core->getRegionPlayerCounts()->getTotalPlayerCount() + count($this->core->getServer()->getOnlinePlayers());
    $ev->getQueryInfo()->setPlayerCount($count);
    $ev->getQueryInfo()->setMaxPlayerCount($count + 1);
  }
  */

}