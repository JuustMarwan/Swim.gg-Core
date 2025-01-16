<?php

namespace core\utils;

use core\systems\entity\entities\CustomEntity;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;

class PacketsHelper
{

  // send an array of data packets to an array of players
  public static function broadCastPacketsToPlayers(array $players, array $packets): void
  {
    foreach ($players as $player) {
      if ($player instanceof Player) {
        self::broadCastPackets($player, $packets);
      }
    }
  }

  // send an array of data packets to a player
  public static function broadCastPackets(Player $player, array $packets): void
  {
    foreach ($packets as $packet) {
      $player->getNetworkSession()->sendDataPacket($packet, true);
    }
  }

  // check if the passed in data packet receive event contained clicking on a specific custom entity
  public static function dataEventDidClickCustomEntity(DataPacketReceiveEvent $event, CustomEntity $customEntity): bool
  {
    /** @var Player $player */
    $player = $event->getOrigin()->getPlayer();
    if ($player !== null) {
      $packet = $event->getPacket();
      /** @var InventoryTransactionPacket $packet */
      /** @var UseItemOnEntityTransactionData $trData */
      if ($packet->pid() == InventoryTransactionPacket::NETWORK_ID) {
        if ($packet->trData->getTypeId() == InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY) {
          $trData = $packet->trData;
          if ($trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK ||
            ($trData->getActionType() === UseItemOnEntityTransactionData::ACTION_INTERACT &&
              $player->getInventory()->getItemInHand()->getTypeId() == VanillaItems::AIR()->getTypeId())) {
            $entityId = $trData->getActorRuntimeId();
            if ($customEntity->getId() == $entityId) {
              return true;
            }
          }
        }
      }
    }
    return false;
  }

}
