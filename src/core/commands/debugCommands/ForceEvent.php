<?php

namespace core\commands\debugCommands;

use core\commands\SeeNick;
use core\custom\prefabs\shopwars\TacticalNuke;
use core\SwimCore;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use core\utils\TargetArgument;
use CortexPE\Commando\args\TextArgument;
use CortexPE\Commando\BaseCommand;
use CortexPE\Commando\exception\ArgumentOrderException;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\Server;

class ForceEvent extends BaseCommand
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct($core, "forceevent", "force a send message event on your behavior components");
    $this->setPermission("use.staff");
    $this->core = $core;
  }

  /**
   * @inheritDoc
   * @throws ArgumentOrderException
   */
  protected function prepare(): void
  {
    $this->setPermission("use.staff");
    $this->registerArgument(0, new TargetArgument("player"));
    $this->registerArgument(1, new TextArgument("message"));
  }

  /**
   * @inheritDoc
   */
  public function onRun(CommandSender $sender, string $aliasUsed, array $args): void
  {
    if ($sender instanceof SwimPlayer) {
      $level = $sender->getRank()->getRankLevel();
      if ($level == Rank::OWNER_RANK) {
        $msg = $args["message"];

        if (isset($args["player"])) {
          $player = SeeNick::getPlayerFromNick($args["player"]);
        } else {
          $player = $sender;
        }

        // dummy event
        $player->getEventBehaviorComponentManager()?->eventMessage(
          new EntityRegainHealthEvent($player, 0, EntityRegainHealthEvent::CAUSE_CUSTOM),
          $msg
        );
      }
    }
  }

}