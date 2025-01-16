<?php

namespace core\utils\acktypes;

use pocketmine\player\GameMode;

class GamemodeChangeAck extends NslAck
{
  public const TYPE = AckType::GAMEMODE_CHANGE;

  public function __construct(public GameMode $newGamemode)
  {

  }
}
