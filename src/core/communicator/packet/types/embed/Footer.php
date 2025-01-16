<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Footer
{

  public function __construct(
    public string $text = "",
    public string $iconUrl = "",
    public string $proxyIconUrl = "",
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->text = $serializer->getString();
    $this->iconUrl = $serializer->getString();
    $this->proxyIconUrl = $serializer->getString();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->text);
    $serializer->putString($this->iconUrl);
    $serializer->putString($this->proxyIconUrl);
  }

}