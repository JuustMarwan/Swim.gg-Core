<?php

namespace core\utils\acktypes;

class EntityRemovalAck extends NslAck
{
  public const TYPE = AckType::ENTITY_REMOVAL;

  public function __construct(public int $actorRuntimeId)
  {

  }
}
