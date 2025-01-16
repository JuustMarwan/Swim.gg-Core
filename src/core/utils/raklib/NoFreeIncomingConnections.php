<?php

namespace core\utils\raklib;

use raklib\protocol\MessageIdentifiers;
use raklib\protocol\OfflineMessage;
use raklib\protocol\PacketSerializer;

class NoFreeIncomingConnections extends OfflineMessage
{

  public static $ID = MessageIdentifiers::ID_NO_FREE_INCOMING_CONNECTIONS;
  public int $serverID;

  public static function create(int $serverID): self
  {
    $result = new self();
    $result->serverID = $serverID;
    return $result;
  }

  protected function encodePayload(PacketSerializer $out): void
  {
    $this->writeMagic($out);
    $out->putLong($this->serverID);
  }

  protected function decodePayload(PacketSerializer $in): void
  {
    $this->readMagic($in);
    $this->serverID = $in->getLong();
  }

}