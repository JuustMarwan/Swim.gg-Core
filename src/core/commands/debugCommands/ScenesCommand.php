<?php

namespace core\commands\debugCommands;

use core\SwimCore;
use core\systems\player\SwimPlayer;
use core\systems\scene\Scene;
use CortexPE\Commando\BaseCommand;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\command\CommandSender;
use pocketmine\player\GameMode;
use pocketmine\utils\TextFormat;

class ScenesCommand extends BaseCommand
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct($core, "scenes", "spectate any scene");
    $this->setPermission("use.staff");
    $this->core = $core;
  }

  /**
   * @inheritDoc
   */
  protected function prepare(): void
  {
    // nop
  }

  /**
   * @inheritDoc
   */
  public function onRun(CommandSender $sender, string $aliasUsed, array $args): void
  {
    if ($sender instanceof SwimPlayer) {
      $buttons = [];

      $form = new SimpleForm(function (SwimPlayer $swimPlayer, $data) use (&$buttons) {
        if ($data === null) {
          return;
        }

        // data in this case will be an int for the index in the buttons array that was clicked
        // Fetch the name of the scene based on the index
        $sceneNames = array_keys($buttons);
        if (isset($sceneNames[$data])) {
          $sceneName = $sceneNames[$data];
          // Now fetch the corresponding scene object using the scene name
          if (isset($buttons[$sceneName])) {
            /* @var Scene $scene */
            $scene = $buttons[$sceneName];
            if ($swimPlayer->getSceneHelper()->setNewScene($sceneName)) {
              $tm = $scene->getTeamManager();
              $tm->getSpecTeam()?->addPlayer($swimPlayer); // this should set them into spectator mode
              $swimPlayer->setGamemode(GameMode::SPECTATOR); // we do it here any ways just in case
              $swimPlayer->sendMessage(TextFormat::GREEN . "Sending you to " . TextFormat::YELLOW . $sceneName);

              // teleport to the first non-spec player in the scene
              foreach ($scene->getPlayers() as $plr) {
                if ($plr->getGamemode() !== GameMode::SPECTATOR) {
                  $swimPlayer->teleport($plr->getPosition());
                  return; // worked so exit
                }
              }

              // otherwise we need to teleport to some spawn point in the scene
              $teams = $tm->getTeams();
              foreach ($teams as $team) {
                if (!$team->isSpecTeam()) {
                  $point = $team->getRandomSpawnPosition(); // abuse this function to get the first spawn point possible
                  if ($point) {
                    $swimPlayer->teleport($point);
                    return; // worked so exit
                  }
                }
              }
            }
          }

          // if unable to warp to a spot or player
          $swimPlayer->sendMessage(TextFormat::YELLOW . "Unable to warp onto a spawn point or player in $sceneName so pray to swimfan that this works without crashing");
        }
      });

      $form->setTitle(TextFormat::DARK_GREEN . "Select Scene to Spectate");

      // we need to iterate all the scenes and push them back into an array that generates mapped buttons
      $ss = $this->core->getSystemManager()->getSceneSystem();
      $scenes = $ss->getScenes();
      foreach ($scenes as $name => $scene) {
        $buttons[$name] = $scene;
        $form->addButton($name);
      }

      $sender->sendForm($form);
    }
  }

}