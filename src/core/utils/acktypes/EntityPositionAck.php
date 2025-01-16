<?php

namespace core\utils\acktypes;

use pocketmine\math\Vector3;

class EntityPositionAck extends NslAck
{
  public const TYPE = AckType::ENTITY_POSITION;

  public function __construct(public Vector3 $pos, public int $actorRuntimeId, public bool $tp)
  {

  }
}
