<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Field
{

  public function __construct(
    public string $name = "",
    public string $value = "",
    public bool   $inline = false,
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->name = $serializer->getString();
    $this->value = $serializer->getString();
    $this->inline = $serializer->getBool();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->name);
    $serializer->putString($this->value);
    $serializer->putBool($this->inline);
  }

}