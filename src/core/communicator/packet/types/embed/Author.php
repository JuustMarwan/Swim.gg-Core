<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Author
{

  public function __construct(
    public string $name = "",
    public string $url = "",
    public string $iconUrl = "",
    public string $proxyIconUrl = "",
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->name = $serializer->getString();
    $this->url = $serializer->getString();
    $this->iconUrl = $serializer->getString();
    $this->proxyIconUrl = $serializer->getString();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->name);
    $serializer->putString($this->url);
    $serializer->putString($this->iconUrl);
    $serializer->putString($this->proxyIconUrl);
  }

}