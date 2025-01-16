<?php


namespace core\communicator\packet;

use core\communicator\Communicator;
use core\communicator\DiscordCommandSender;

class DiscordCommandExecutePacket extends Packet
{
  public const NETWORK_ID = PacketId::DISCORD_COMMAND_EXECUTE;

  public string $commandLine;
  public string $requestId;
  public string $senderName;
  public string $senderUserId;
  public string $channelId;

  /** @var string[] */
  public array $senderRoles;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->commandLine = $serializer->getString();
    $this->requestId = $serializer->getString();
    $this->senderName = $serializer->getString();
    $this->senderUserId = $serializer->getString();
    $this->channelId = $serializer->getString();
    $this->senderRoles = $serializer->getArray($serializer->getString(...));
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->commandLine);
    $serializer->putString($this->requestId);
    $serializer->putString($this->senderName);
    $serializer->putString($this->senderUserId);
    $serializer->putString($this->channelId);
    $serializer->putArray($this->senderRoles, $serializer->putString(...));
  }

  protected function handle(Communicator $communicator): void
  {
    $sender = new DiscordCommandSender
    (
      $communicator,
      $communicator->getCore()->getServer()->getLanguage(),
      $communicator->getCore(),
      $this->requestId,
      in_array($communicator->getDiscordInfo()->ownerRole, $this->senderRoles) ? null : ["use.staff"],
      $this->senderRoles,
      $this->senderName,
      $this->channelId,
      $this->senderUserId
    );
    $communicator->getCore()->getServer()->dispatchCommand($sender, $this->commandLine);
  }

}