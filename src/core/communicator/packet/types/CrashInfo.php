<?php

namespace core\communicator\packet\types;

use core\communicator\packet\PacketSerializer;

class CrashInfo
{
  public string $type;
  public string $message;
  public string $file;
  public int $line;
  public array $trace;

  public function __construct()
  {

  }

  public static function create(string $type, string $message, string $file, int $line, array $trace): self
  {
    $info = new self;
    $info->type = $type;
    $info->message = $message;
    $info->file = $file;
    $info->line = $line;
    $info->trace = $trace;
    return $info;
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->type);
    $serializer->putString($this->message);
    $serializer->putString($this->file);
    $serializer->putLShort($this->line);
    $serializer->putArray($this->trace, $serializer->putString(...));
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->type = $serializer->getString();
    $this->message = $serializer->getString();
    $this->file = $serializer->getString();
    $this->line = $serializer->getLShort();
    $this->trace = $serializer->getArray($serializer->getString(...));
  }

}