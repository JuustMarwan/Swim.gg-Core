<?php

namespace core\utils\acktypes;

class NoAiAck extends NslAck
{
  public const TYPE = AckType::NO_AI;

  public function __construct(public bool $noAi)
  {

  }
}
