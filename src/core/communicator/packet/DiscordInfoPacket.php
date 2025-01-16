<?php

namespace core\communicator\packet;

use core\communicator\Communicator;

class DiscordInfoPacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_INFO;

  public string $boosterRole;
  public string $youtubeRole;
  public string $helperRole;
  public string $modRole;
  public string $ownerRole;
  public string $acChannel;
  public string $linkAlertsChannel;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->boosterRole = $serializer->getString();
    $this->youtubeRole = $serializer->getString();
    $this->helperRole = $serializer->getString();
    $this->modRole = $serializer->getString();
    $this->ownerRole = $serializer->getString();
    $this->acChannel = $serializer->getString();
    $this->linkAlertsChannel = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->boosterRole);
    $serializer->putString($this->youtubeRole);
    $serializer->putString($this->helperRole);
    $serializer->putString($this->modRole);
    $serializer->putString($this->ownerRole);
    $serializer->putString($this->acChannel);
    $serializer->putString(str: $this->linkAlertsChannel);
  }

  protected function handle(Communicator $communicator): void
  {
    $info = $communicator->getDiscordInfo();
    $info->boosterRole = $this->boosterRole;
    $info->youtubeRole = $this->youtubeRole;
    $info->helperRole = $this->helperRole;
    $info->modRole = $this->modRole;
    $info->ownerRole = $this->ownerRole;
    $info->acChannel = $this->acChannel;
    $info->linkAlertsChannel = $this->linkAlertsChannel;
  }

}