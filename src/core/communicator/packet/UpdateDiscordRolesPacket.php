<?php

namespace core\communicator\packet;

class UpdateDiscordRolesPacket extends Packet
{
  public const NETWORK_ID = PacketId::UPDATE_DISCORD_ROLES;

  public const ACTION_ROLE_ADD = 0;
  public const ACTION_ROLE_REMOVE = 1;

  public string $userId;
  public int $action;
  public string $role;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->userId = $serializer->getString();
    $this->action = $serializer->getByte();
    $this->role = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->userId);
    $serializer->putByte($this->action);
    $serializer->putString($this->role);
  }

}