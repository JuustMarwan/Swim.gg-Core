<?php

namespace core\utils\raklib;

use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\PacketSerializer;

class SecureOpenConnectionReply1 extends OpenConnectionReply1
{

  public int $handshakeId;

  public static function create(int $serverId, bool $serverSecurity, int $mtuSize, int $handshakeId = 0): self
  {
    $result = new self;
    $result->serverID = $serverId;
    $result->serverSecurity = $serverSecurity;
    $result->mtuSize = $mtuSize;
    $result->handshakeId = $handshakeId;
    return $result;
  }

  protected function encodePayload(PacketSerializer $out): void
  {
    $this->writeMagic($out);
    $out->putLong($this->serverID);
    $out->putByte($this->serverSecurity ? 1 : 0);
    $out->putInt($this->handshakeId);
    $out->putShort($this->mtuSize);
  }

  protected function decodePayload(PacketSerializer $in): void
  {
    $this->readMagic($in);
    $this->serverID = $in->getLong();
    $this->serverSecurity = $in->getByte() !== 0;
    $this->handshakeId = $in->getInt();
    $this->mtuSize = $in->getShort();
  }

}
