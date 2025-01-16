<?php


namespace core\communicator\packet;

use core\communicator\Communicator;

class PlayerListRequestPacket extends Packet
{
  public const NETWORK_ID = PacketId::PLAYER_LIST_REQUEST;

  public string $regionName = "";

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->regionName = $serializer->getString();
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putString($this->regionName);
  }

  protected function handle(Communicator $communicator): void
  {
    $resp = new PlayerListResponsePacket;
    $resp->regionName = $communicator->getCore()->getRegionInfo()->regionName;
    $players = $communicator->getCore()->getServer()->getOnlinePlayers();
    $playerNames = [];
    // Iterate over each player and get their name
    foreach ($players as $player) {
      $playerNames[] = $player->getName();
    }
    $resp->players = $playerNames;
    $communicator->write($resp->encodeToString());
  }
}