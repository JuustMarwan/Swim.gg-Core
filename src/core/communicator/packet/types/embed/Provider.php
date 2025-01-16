<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Provider
{

  public function __construct(
    public string $name = "",
    public string $url = "",
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->name = $serializer->getString();
    $this->url = $serializer->getString();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->name);
    $serializer->putString($this->url);
  }

}