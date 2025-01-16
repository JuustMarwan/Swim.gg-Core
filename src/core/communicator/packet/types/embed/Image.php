<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Image
{

  public function __construct(
    public string $url = "",
    public string $proxyUrl = "",
    public int    $width = 0,
    public int    $height = 0,
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->url = $serializer->getString();
    $this->proxyUrl = $serializer->getString();
    $this->width = $serializer->getShort();
    $this->height = $serializer->getShort();
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->url);
    $serializer->putString($this->proxyUrl);
    $serializer->putShort($this->width);
    $serializer->putShort($this->height);
  }

}