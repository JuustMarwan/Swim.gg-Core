<?php

namespace core\communicator\packet;

use core\communicator\Communicator;
use core\systems\player\SwimPlayer;

class DiscordLinkInfoPacket extends Packet
{

  public const NETWORK_ID = PacketId::DISCORD_LINK_INFO;

  private string $ign;
  private string $discordName;
  private string $discordId;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->ign = $serializer->getString();
    $this->discordName = $serializer->getString();
    $this->discordId = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->ign);
    $serializer->putString($this->discordName);
    $serializer->putString($this->discordId);
  }

  protected function handle(Communicator $communicator): void
  {
    $player = $communicator->getCore()->getServer()->getPlayerExact($this->ign);
    if (!$player instanceof SwimPlayer) {
      return;
    }
    $player->handleDiscordLinkRequest($this->discordName, $this->discordId);
  }

}
