<?php

namespace core\communicator\packet;

use core\communicator\packet\types\CrashInfo;
use core\communicator\packet\types\DisconnectReason;
use pocketmine\network\mcpe\protocol\PacketDecodeException;

class DisconnectPacket extends Packet
{

  public const NETWORK_ID = PacketId::DISCONNECT;

  public DisconnectReason $reason;
  public CrashInfo $crashInfo;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $reason = $serializer->getByte();
    $this->reason = DisconnectReason::tryFrom($reason);
    if ($this->reason === null) {
      throw new PacketDecodeException("Invalid disconnect reason " . $reason);
    }
    if ($this->reason->value === DisconnectReason::SERVER_CRASH) {
      $this->crashInfo = new CrashInfo;
      $this->crashInfo->decode($serializer);
    }
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putByte($this->reason->value);
    if ($this->reason === DisconnectReason::SERVER_CRASH && isset($this->crashInfo)) {
      $this->crashInfo->encode($serializer);
    }
  }

}