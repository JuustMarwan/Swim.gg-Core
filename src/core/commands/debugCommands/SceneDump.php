<?php

namespace core\commands\debugCommands;

use core\SwimCore;
use core\systems\player\SwimPlayer;
use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\BaseCommand;
use CortexPE\Commando\exception\ArgumentOrderException;
use pocketmine\command\CommandSender;

class SceneDump extends BaseCommand
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct($core, "scenedump", "dump the scene you are in, or a target scene");
    $this->setPermission("use.staff");
    $this->core = $core;
  }

  /**
   * @inheritDoc
   * @throws ArgumentOrderException
   */
  protected function prepare(): void
  {
    $this->registerArgument(0, new RawStringArgument("scene name", true));
  }

  /**
   * @inheritDoc
   */
  public function onRun(CommandSender $sender, string $aliasUsed, array $args): void
  {
    if (!($sender instanceof SwimPlayer)) return;

    if (isset($args["scene name"])) {
      $scene = $this->core->getSystemManager()->getSceneSystem()->getScene($args["scene name"]);
      if (!isset($scene)) return;
    } else {
      $scene = $sender->getSceneHelper()?->getScene();
      if (!isset($sender)) return;
    }

    // dump scene name
    $title = $scene->getSceneName() . " Info:";
    $sender->sendMessage($title);
    echo($title . "\n");

    // $world = $scene->getWorld()->getFolderName(); // this would be useful but causes crashes in some scenes
    $playerCount = count($scene->getPlayers());
    $age = $scene->getSceneTickAge();
    $isDuel = $scene->isDuel() ? "True" : "False";
    $isFFA = $scene->isFFA() ? "True" : "False";

    $info = "Player Count: $playerCount | Tick Age: $age | Is Duel: $isDuel | Is FFA: $isFFA";
    $sender->sendMessage($info);
    echo($info . "\n");

    // dump the teams
    foreach ($scene->getTeamManager()->getTeams() as $team) {
      $msg = $team->getTeamName() . " | Score: " . $team->getScore() . " | ID: " . $team->getTeamID();
      echo($msg . "\n");
      $sender->sendMessage($msg);
      foreach ($team->getPlayers() as $player) {
        echo(" " . $player->getName() . "\n");
        $sender->sendMessage(" " . $player->getName());
      }
    }

  }

}