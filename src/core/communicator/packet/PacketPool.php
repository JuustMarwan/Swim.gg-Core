<?php

namespace core\communicator\packet;

use SplFixedArray;

class PacketPool
{

  private SplFixedArray $packets;

  public function __construct()
  {
    $this->packets = new SplFixedArray(255);
    $this->registerPacket(new ServerInfoPacket);
    $this->registerPacket(new PlayerListRequestPacket);
    $this->registerPacket(new PlayerListResponsePacket);
    $this->registerPacket(new DiscordCommandExecutePacket);
    $this->registerPacket(new DiscordCommandMessagePacket);
    $this->registerPacket(new DisconnectPacket);
    $this->registerPacket(new DiscordUserRequestPacket);
    $this->registerPacket(new DiscordUserResponsePacket);
    $this->registerPacket(new DiscordLinkRequestPacket);
    $this->registerPacket(new DiscordLinkInfoPacket);
    $this->registerPacket(new OtherRegionsPacket);
    $this->registerPacket(new DiscordInfoPacket);
    $this->registerPacket(new UpdateDiscordRolesPacket);
  }

  public function registerPacket(Packet $packet): void
  {
    $this->packets[$packet->pid()->value] = $packet;
  }

  public function getPacketById(int $id): ?Packet
  {
    return isset($this->packets[$id]) ? clone $this->packets[$id] : null;
  }

}