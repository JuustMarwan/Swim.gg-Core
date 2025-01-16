<?php

namespace core\communicator\packet;

use core\communicator\Communicator;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use ReflectionClass;

abstract class Packet
{

  public const NETWORK_ID = PacketId::UNKNOWN;

  public function pid(): PacketId
  {
    return $this::NETWORK_ID;
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putByte($this->pid()->value);
    $this->encodePayload($serializer);
  }

  public function getName(): string
  {
    return (new ReflectionClass(objectOrClass: $this))->getShortName();
  }

  public static function decode(PacketSerializer $serializer, Communicator $communicator): ?self
  {
    try {
      $id = $serializer->getByte();
    } catch (BinaryDataException $e) {
      throw PacketDecodeException::wrap($e);
    }
    $packet = $communicator->getPacketPool()->getPacketById($id);
    if ($packet !== null) {
      try {
        $packet->decodePayload($serializer);
      } catch (BinaryDataException|PacketDecodeException $e) {
        throw PacketDecodeException::wrap($e, $packet->getName());
      }
      $packet->handle($communicator);
      return $packet;
    } else {
      throw new PacketDecodeException("Unknown Packet ID " . $id);
    }
  }

  public function encodeToString(): string
  {
    $serializer = new PacketSerializer();
    $this->encode($serializer);
    $buf = $serializer->getBuffer();
    return Binary::writeShort(strlen($buf)) . $serializer->getBuffer();
  }

  abstract protected function encodePayload(PacketSerializer $serializer): void;

  abstract protected function decodePayload(PacketSerializer $serializer): void;

  protected function handle(Communicator $communicator): void
  {

  }

}