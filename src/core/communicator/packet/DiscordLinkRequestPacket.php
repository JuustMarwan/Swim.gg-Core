<?php

namespace core\communicator\packet;

class DiscordLinkRequestPacket extends Packet
{

  public const NETWORK_ID = PacketId::DISCORD_LINK_REQUEST;

  public string $ign;
  public bool $remove = false;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->ign = $serializer->getString();
    $this->remove = $serializer->getBool();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->ign);
    $serializer->putBool($this->remove);
  }

}