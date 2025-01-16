<?php

namespace core\utils\loaders;

use core\listeners\WorldListener;
use core\utils\FileUtil;
use pocketmine\Server;
use ReflectionException;

class WorldLoader
{

  private static array $worlds;

  /**
   * @throws ReflectionException
   */
  public static function loadWorlds(string $folder): void
  {
    // get our worlds path
    $worldsFolder = $folder . DIRECTORY_SEPARATOR . 'worlds';
    $savedWorldsFolder = $folder . DIRECTORY_SEPARATOR . 'savedWorlds';

    // iterate the saved worlds folder and save each directory name string to the worlds array
    self::$worlds = FileUtil::GetDirectories($savedWorldsFolder);

    // load each world in
    $worldManager = Server::getInstance()->getWorldManager();
    foreach (self::$worlds as $worldName) {
      // delete the world
      FileUtil::RecurseDelete($worldsFolder . DIRECTORY_SEPARATOR . $worldName);
      // copy in the saved world
      FileUtil::RecurseCopy($savedWorldsFolder . DIRECTORY_SEPARATOR . $worldName, $worldsFolder . DIRECTORY_SEPARATOR . $worldName);
      // load in the freshly copied world
      $worldManager->loadWorld($worldName, true);
      $world = $worldManager->getWorldByName($worldName); // get the actual world object to call methods on it after loading
      if ($world === null) {
        echo("ERROR, Null world: " . $worldName . "\n");
      } else {
        echo("Loaded world from SavedWorlds: " . $worldName . "\n");
        WorldListener::disableWorldLogging($world);
      }
      $world->setTime(1600);
      $world->stopTime();
      $world->setDifficulty(3); // hard to make it so regen is not super high
    }
    $worldManager->setAutoSave(false); // disable worlds saving chunk changes on shutdown (be careful)
  }

  public static function getWorldPlayerCount(string $worldName): int
  {
    $world = Server::getInstance()->getWorldManager()->getWorldByName($worldName);
    if ($world) {
      return count($world->getPlayers());
    }
    return 0;
  }

}
