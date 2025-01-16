<?php

namespace core\scenes\hub;

use core\custom\behaviors\player_event_behaviors\MaxDistance;
use core\custom\prefabs\hub\HubEntities;
use core\forms\hub\FormDuelRequests;
use core\forms\hub\FormDuels;
use core\forms\hub\FormEvents;
use core\forms\hub\FormFFA;
use core\forms\hub\FormSettings;
use core\forms\hub\FormSpectate;
use core\forms\parties\FormPartyCreate;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use core\systems\scene\Scene;
use core\utils\BehaviorEventEnums;
use core\utils\InventoryUtil;
use core\utils\PositionHelper;
use jojoe77777\FormAPI\SimpleForm;
use jackmd\scorefactory\ScoreFactory;
use jackmd\scorefactory\ScoreFactoryException;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\player\GameMode;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use ReflectionException;

class Hub extends Scene
{

  public static function AutoLoad(): bool
  {
    return true;
  }

  /**
   * @throws ReflectionException
   */
  function init(): void
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

    $this->setWorld($this->core->getHubWorld());

    // spawn in our hub entities for the scene
    HubEntities::spawnToScene($this);
  }

  public function playerAdded(SwimPlayer $player): void
  {
    $player->getEventBehaviorComponentManager()?->clear(false);
    InventoryUtil::fullPlayerReset($player);
    $player->getEventBehaviorComponentManager()->registerComponent(new MaxDistance("max", $this->core, $player));
    $this->restart($player);
    if ($player->getRank()?->getRankLevel() ?? 0 >= Rank::BOOSTER_RANK) $player->setAllowFlight(true);
  }

  // when leaving the hub we remove any duel invites they sent to any players who are in the hub
  public function playerRemoved(SwimPlayer $player): void
  {
    $name = $player->getName();
    foreach ($this->players as $plr) {
      if ($plr instanceof SwimPlayer) {
        if ($plr !== $player) {
          $plr->getInvites()->prunePlayerFromDuelInvites($name);
        }
      }
    }
  }

  public function restart(SwimPlayer $swimPlayer): void
  {
    $this->teleportToHub($swimPlayer);
    $this->setHubKit($swimPlayer);
    $this->setHubTags($swimPlayer);
    $swimPlayer->getCosmetics()->refresh();
    $swimPlayer->setGamemode(GameMode::ADVENTURE);
    $swimPlayer->getNetworkSession()->sendDataPacket(LevelEventPacket::create(LevelEvent::STOP_RAIN, 10000, null));
  }

  private function teleportToHub(SwimPlayer $player): void
  {
    $hub = $this->core->getHubWorld();
    $safeSpawn = $hub->getSafeSpawn();
    $player->teleport(PositionHelper::centerPosition($safeSpawn), 90, 0);
  }

  private function setHubKit(SwimPlayer $player): void
  {
    $inventory = $player->getInventory();
    $inventory->setHeldItemIndex(4);
    $inventory->clearAll();
    if ($this->core->getRegionInfo()->isHub()) {
      // $inventory->setItem(0, new ServerSelectorCompass());
    } else {
      $inventory->setItem(0, VanillaItems::DIAMOND_SWORD()->setCustomName("§bFFA §7[Right Click]")->setUnbreakable());
      $inventory->setItem(1, VanillaItems::IRON_SWORD()->setCustomName("§fDuels §7[Right Click]")->setUnbreakable());
      $inventory->setItem(2, VanillaItems::TOTEM()->setCustomName("§aDuel Requests §7[Right Click]"));
      $inventory->setItem(3, VanillaItems::PAPER()->setCustomName("§bSpectate Matches §7[Right Click]"));
      $inventory->setItem(5, VanillaItems::NETHER_STAR()->setCustomName("§bEvents §7[Right Click]"));
      $inventory->setItem(6, VanillaItems::EMERALD()->setCustomName("§dEdit Kits §7[Right Click]"));
      $inventory->setItem(7, VanillaBlocks::CAKE()->asItem()->setCustomName("§aParties §7[Right Click]"));
      $inventory->setItem(8, VanillaItems::BOOK()->setCustomName("§bManage Settings §7[Right Click]"));
    }
  }

  // Nick component uses this
  public static function setHubTags(SwimPlayer $swimPlayer): void
  {
    // $swimPlayer->genericNameTagHandling();
    $swimPlayer->getCosmetics()->tagNameTag();
    $swimPlayer->getRank()->rankScoreTag();
  }

  // at scene update we call the scoreboard behavior function

  /**
   * @throws ScoreFactoryException
   */
  function updateSecond(): void
  {
    foreach ($this->players as $player) {
      $this->hubBoard($player);
    }
  }

  /**
   * @throws ScoreFactoryException
   */
  private function hubBoard(SwimPlayer $swimPlayer): void
  {
    $player = $swimPlayer;
    if ($swimPlayer->isScoreboardEnabled()) {
      try {
        $swimPlayer->refreshScoreboard(TextFormat::AQUA . "Swimgg.club");
        ScoreFactory::sendObjective($player);
        $ping = $swimPlayer->getNslHandler()->getPing();
        if ($this->core->getRegionInfo()->isHub()) {
          ScoreFactory::setScoreLine($player, 1, " §bPing: §3" . $ping);
          ScoreFactory::setScoreLine($player, 2, " §bdiscord.gg/§3swim");
          ScoreFactory::setScoreLine($player, 3, " §bswimgg.§3club");
          $line = 4;
          foreach ($this->core->getCommunicator()->getAllRegionPlayers() as $region => $players) {
            $numPlayers = isset($players) ? count(value: $players) : "§COffline";
            ScoreFactory::setScoreLine($player, $line, " §b" . $region . ":§f " . $numPlayers);
            $line++;
          }
        } else {
          // variables needed
          $onlineCount = count($player->getServer()->getOnlinePlayers());
          $maxPlayers = $player->getServer()->getMaxPlayers();
          // define lines
          ScoreFactory::setScoreLine($player, 1, " §bOnline: §f" . $onlineCount . "§7 / §3" . $maxPlayers);
          ScoreFactory::setScoreLine($player, 2, " §bPing: §3" . $ping);
          ScoreFactory::setScoreLine($player, 3, " §bQueued: §3" . $this->sceneSystem->getQueuedCount());
          ScoreFactory::setScoreLine($player, 4, " §bIn Duel: §3" . $this->sceneSystem->getInDuelsCount());
          ScoreFactory::setScoreLine($player, 5, " §bIn FFA: §3" . $this->sceneSystem->getInFFACount());
          ScoreFactory::setScoreLine($player, 6, " §bswimgg.§3club");
          ScoreFactory::setScoreLine($player, 7, " §bdiscord.gg/§3swim");
        }
        // send lines
        ScoreFactory::sendLines($player);
      } catch (ScoreFactoryException $e) {
        Server::getInstance()->getLogger()->info($e->getMessage());
      }
    }
  }

  // when placing cake
  public function sceneBlockPlaceEvent(BlockPlaceEvent $event, SwimPlayer $swimPlayer): void
  {
    foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
      if ($block instanceof Block) {
        if ($block->getTypeId() == VanillaBlocks::CAKE()->getTypeId()) {
          FormPartyCreate::partyBaseForm($this->core, $swimPlayer);
        }
      }
    }
    $event->cancel();
  }

  // using hub items to open forms
  // TO DO : use custom derived item classes with their own embedded on use callbacks to avoid this string-switch malarkey
  public function sceneItemUseEvent(PlayerItemUseEvent $event, SwimPlayer $swimPlayer): void
  {
    $item = $swimPlayer->getInventory()->getItemInHand();
    $name = $item->getCustomName();

    // party items instead
    $sh = $swimPlayer->getSceneHelper();
    if ($sh->isInParty()) {
      $sh->getParty()?->partyItemHandle($swimPlayer, $name);
      return;
    }

    // regular
    switch ($name) {
      case "§bFFA §7[Right Click]":
        FormFFA::ffaSelectionForm($swimPlayer);
        break;
      case "§fDuels §7[Right Click]":
        FormDuels::duelBaseForm($swimPlayer);
        break;
      case "§bManage Settings §7[Right Click]":
        FormSettings::settingsForm($swimPlayer);
        break;
      case "§aParties §7[Right Click]":
        FormPartyCreate::partyBaseForm($this->core, $swimPlayer);
        break;
      case "§bSpectate Matches §7[Right Click]":
        FormSpectate::spectateSelectionForm($this->core, $swimPlayer);
        break;
      case "§aDuel Requests §7[Right Click]":
        FormDuelRequests::duelSelectionBase($this->core, $swimPlayer);
        break;
      case "§dEdit Kits §7[Right Click]":
        $this->editKitConfirm($swimPlayer);
        break;
      case "§bEvents §7[Right Click]":
        FormEvents::eventForm($this->core, $swimPlayer);
        break;
    }
  }

  // TO DO: update the form api virion to work properly for modal form because null is returned on closing and that isn't handled properly
  public static function editKitConfirm(SwimPlayer $swimPlayer): void
  {
    $form = new SimpleForm(function (SwimPlayer $player, $data) {
      if ($data === null) {
        return false;
      }

      if ($data == 0) {
        $player->getSceneHelper()->setNewScene('KitEditor');
      }
      return true;

    });

    $form->setTitle("Kit Editor");
    $form->setContent("Go to kit Editor?");
    $form->addButton(TextFormat::GREEN . "Yes");
    $form->addButton(TextFormat::RED . "No, Stay in Hub");
    $swimPlayer->sendForm($form);
  }

} // class Hub
