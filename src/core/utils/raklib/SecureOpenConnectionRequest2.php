<?php

namespace core\utils\raklib;

use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\PacketSerializer;

class SecureOpenConnectionRequest2 extends OpenConnectionRequest2
{

  public int $handshakeId;
  public int $byte1;

  protected function encodePayload(PacketSerializer $out): void
  {
    $this->writeMagic($out);
    $out->putInt($this->handshakeId);
    $out->putByte($this->byte1);
    $out->putAddress($this->serverAddress);
    $out->putShort($this->mtuSize);
    $out->putLong($this->clientID);
  }

  protected function decodePayload(PacketSerializer $in): void
  {
    $this->readMagic($in);
    $this->handshakeId = $in->getInt();
    $this->byte1 = $in->getByte();
    $this->serverAddress = $in->getAddress();
    $this->mtuSize = $in->getShort();
    $this->clientID = $in->getLong();
  }

}
