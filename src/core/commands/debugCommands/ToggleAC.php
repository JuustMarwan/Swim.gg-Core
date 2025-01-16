<?php

namespace core\commands\debugCommands;

use core\SwimCore;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class ToggleAC extends Command
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct("ac", "toggle the ac on");
    $this->core = $core;
    $this->setPermission("use.staff");
  }

  public function execute(CommandSender $sender, string $commandLabel, array $args): bool
  {
    if ($sender instanceof SwimPlayer) {
      $rank = $sender->getRank()->getRankLevel();
      if ($rank == Rank::OWNER_RANK) {
        SwimCore::$AC = !SwimCore::$AC;
        $str = SwimCore::$AC ? "true" : "false";
        $sender->sendMessage(TextFormat::GREEN . "AntiCheat toggled to " . $str . " (will still flag just not ban or kick)");
      } else {
        $sender->sendMessage(TextFormat::RED . "You can not use this");
      }
    }
    return true;
  }

}