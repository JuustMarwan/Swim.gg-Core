<?php

namespace core\utils\acktypes;

use pocketmine\math\Vector3;

class KnockbackAck extends NslAck
{
  public const TYPE = AckType::KNOCKBACK;

  public function __construct(public Vector3 $motion)
  {

  }
}
