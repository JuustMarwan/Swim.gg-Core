<?php

namespace core\systems\entity\entities;

use pocketmine\entity\Location;

trait DeltaSupportTrait
{

  private Location $prevPos;

  public bool $supportsDelta = true;

  public function getPrevPos(): Location
  {
    return $this->prevPos;
  }

  public function updateMovement(bool $teleport = false): void
  {
    $this->prevPos = clone $this->lastLocation;
    parent::updateMovement($teleport);
  }

}