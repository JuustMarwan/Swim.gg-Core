<?php

namespace core\communicator\packet\types;

use core\communicator\packet\PacketSerializer;

class Region
{

  public string $ip;
  public int $port;
  public string $displayName;

  public function decode(PacketSerializer $serializer): void
  {
    $this->ip = $serializer->getString();
    $this->port = $serializer->getLShort();
    $this->displayName = $serializer->getString();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->ip);
    $serializer->putLShort($this->port);
    $serializer->putString($this->displayName);
  }

}