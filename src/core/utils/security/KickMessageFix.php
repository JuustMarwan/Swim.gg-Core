<?php

namespace core\utils\security;

use core\SwimCore;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;

class KickMessageFix
{

  // using player->kick() directly doesn't always show the message due to a client bug
  public static function kick(SwimCore $core, Player $player, string $msg): void
  {
    $player->getNetworkSession()->sendDataPacket(DisconnectPacket::create(0, $msg, $msg)); // send a disconnect packet with the message
    $core->getLogger()->info("[KickMessageFix] " . $player->getName() . " was disconnected: " . str_replace("\n", " ", $msg));

    // force close after a second if the client does not kick from the disconnect packet (hackers might not get kicked just from a disconnect packet)
    $core->getScheduler()->scheduleDelayedTask(new class($player) extends Task {
      private Player $player;

      public function __construct(Player $player)
      {
        $this->player = $player;
      }

      public function onRun(): void
      {
        $this->player->kick();
      }
    }, 20);
  }

}