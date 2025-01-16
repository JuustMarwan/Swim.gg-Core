<?php

namespace core\communicator\packet;

use core\communicator\packet\types\embed\Embed;

class DiscordEmbedSendPacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_EMBED_SEND;

  public string $channelId;
  public Embed $embed;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->channelId = $serializer->getString();
    $this->embed = new Embed;
    $this->embed->decode($serializer);
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->channelId);
    $this->embed->encode($serializer);
  }
}