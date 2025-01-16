<?php


namespace core\communicator\packet;

use core\communicator\Communicator;

class DiscordUserResponsePacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_USER_RESPONSE;

  public string $requestId;
  public string $error;
  public string $name;
  public string $displayName;
  public string $nickname;
  public string $avatarUrl;
  /** @var string[] */
  public array $roles;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->requestId = $serializer->getString();
    $this->error = $serializer->getString();
    if (strlen($this->error) === 0) {
      $this->name = $serializer->getString();
      $this->displayName = $serializer->getString();
      $this->nickname = $serializer->getString();
      $this->avatarUrl = $serializer->getString();
      $this->roles = $serializer->getArray($serializer->getString(...));
    }
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->requestId);
    $serializer->putString($this->error);
    if (strlen($this->error) === 0) {
      $serializer->putString($this->name);
      $serializer->putString($this->displayName);
      $serializer->putString($this->nickname);
      $serializer->putArray($this->roles, $serializer->putString(...));
    }
  }

  protected function handle(Communicator $communicator): void
  {
    $communicator->onDiscordUserResponse($this);
  }

}