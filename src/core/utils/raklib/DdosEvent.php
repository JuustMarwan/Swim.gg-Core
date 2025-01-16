<?php

namespace core\utils\raklib;

use pocketmine\event\server\ServerEvent;
use pocketmine\network\mcpe\raklib\RakLibInterface;

enum DdosEventType
{
  case DDOS_STARTED;
  case DDOS_ENDED;
}

class DdosEvent extends ServerEvent
{

  public function __construct
  (
    private readonly DdosEventType $type,
    private readonly RakLibInterface $interface
  )
  {
  }

  public function getType(): DdosEventType
  {
    return $this->type;
  }

  public function getRakLibInterface(): RakLibInterface
  {
    return $this->interface;
  }

}