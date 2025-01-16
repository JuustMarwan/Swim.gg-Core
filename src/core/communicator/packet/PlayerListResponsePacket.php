<?php


namespace core\communicator\packet;

use core\communicator\Communicator;

class PlayerListResponsePacket extends Packet
{
  public const NETWORK_ID = PacketId::PLAYER_LIST_RESPONSE;

  public string $regionName = "";
  public bool $offline = false;
  /** @var string[] */
  public ?array $players = null;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->regionName = $serializer->getString();
    $this->offline = $serializer->getBool();
    if (!$this->offline) {
      $this->players = $serializer->getArray($serializer->getString(...));
    }
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->regionName);
    $serializer->putBool($this->offline);
    if (!$this->offline) {
      $serializer->putArray($this->players, $serializer->putString(...));
    }
  }

  protected function handle(Communicator $communicator): void
  {
    if ($this->regionName === $communicator->getCore()->getRegionInfo()->regionName) {
      return;
    }
    $communicator->updateRegionPlayers($this->regionName, $this->players);
  }
}