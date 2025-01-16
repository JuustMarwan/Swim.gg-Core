<?php


namespace core\communicator\packet;

class DiscordCommandMessagePacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_COMMAND_MESSAGE;

  public string $commandMessage;
  public string $requestId;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->commandMessage = $serializer->getString();
    $this->requestId = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->commandMessage);
    $serializer->putString($this->requestId);
  }
}