<?php

namespace core\communicator\packet;

use core\communicator\Communicator;
use core\communicator\packet\types\Region;

class OtherRegionsPacket extends Packet
{
  public const NETWORK_ID = PacketId::OTHER_REGIONS;

  /** @var Region[] */
  public array $regions;

  protected function decodePayload(PacketSerializer $serializer): void
  {
    $this->regions = $serializer->getMap($serializer->getString(...), function () use ($serializer) {
      $region = new Region;
      $region->decode($serializer);
      return $region;
    });
  }

  protected function encodePayload(PacketSerializer $serializer): void
  {
    $serializer->putMap($this->regions, $serializer->putString(...), function (Region $region) use ($serializer) {
      $region->encode($serializer);
    });
  }

  protected function handle(Communicator $communicator): void
  {
    $communicator->setOtherRegions($this->regions);
  }

}