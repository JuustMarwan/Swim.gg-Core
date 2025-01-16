<?php

namespace core\communicator\packet;

use Closure;
use pocketmine\utils\BinaryStream;

class PacketSerializer extends BinaryStream
{

  public function putString(string $str)
  {
    $this->putUnsignedVarInt(strlen($str));
    $this->put($str);
  }

  public function getString(): string
  {
    $len = $this->getUnsignedVarInt();
    return $this->get($len);
  }

  public function putArray(array $arr, Closure $writer)
  {
    $this->putUnsignedVarInt(count(value: $arr));
    foreach ($arr as $elem) {
      $writer($elem);
    }
  }

  public function getArray(Closure $reader): array
  {
    $arr = [];
    $len = $this->getUnsignedVarInt();
    for ($i = 0; $i < $len; $i++) {
      $arr[] = $reader();
    }
    return $arr;
  }

  public function putMap(array $map, Closure $keyWriter, Closure $valueWriter)
  {
    $this->putUnsignedVarInt(count(value: $map));
    foreach ($map as $key => $elem) {
      $keyWriter($key);
      $valueWriter($elem);
    }
  }

  public function getMap(Closure $keyReader, Closure $valueReader): array
  {
    $arr = [];
    $len = $this->getUnsignedVarInt();
    for ($i = 0; $i < $len; $i++) {
      $key = $keyReader();
      $arr[$key] = $valueReader();
    }
    return $arr;
  }

  /**
   * @phpstan-template T
   * @phpstan-param Closure() : T $reader
   * @phpstan-return T|null
   */
  public function getOptional(Closure $reader): mixed
  {
    if ($this->getBool()) {
      return $reader();
    }
    return null;
  }

  /**
   * @phpstan-template T
   * @phpstan-param T|null $value
   * @phpstan-param Closure(T) : void $writer
   */
  public function putOptional(mixed $value, Closure $writer): void
  {
    if ($value !== null) {
      $this->putBool(true);
      $writer($value);
    } else {
      $this->putBool(false);
    }
  }

}