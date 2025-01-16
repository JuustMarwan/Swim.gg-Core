<?php

namespace core\communicator\packet;

class ServerInfoPacket extends Packet
{
  public const NETWORK_ID = PacketId::SERVER_INFO;

  public string $regionName;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->regionName = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->regionName);
  }
}