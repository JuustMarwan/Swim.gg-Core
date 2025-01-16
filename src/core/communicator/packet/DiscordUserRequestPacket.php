<?php

namespace core\communicator\packet;

use core\communicator\Communicator;
use core\communicator\DiscordCommandSender;

class DiscordUserRequestPacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_USER_REQUEST;

  public string $requestId;
  public string $userId;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->requestId = $serializer->getString();
    $this->userId = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->requestId);
    $serializer->putString($this->userId);
  }
}