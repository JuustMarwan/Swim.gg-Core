<?php

namespace core\utils;

use core\SwimCore;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use function in_array;
use function str_replace;
use function str_starts_with;

final class ProtocolIdToVersion
{

  private static array $map;

  public static function init(): void
  {
    if (SwimCore::$isNetherGames) {
      $infoRefl = new \ReflectionClass(ProtocolInfo::class);
      foreach ($infoRefl->getConstants() as $name => $const) {
        if (in_array($const, ProtocolInfo::ACCEPTED_PROTOCOL, true) && str_starts_with($name, "PROTOCOL_")) {
          $versionPart = str_replace("PROTOCOL_", "v", $name);
          $version = str_replace("_", ".", $versionPart);
          self::$map[$const] = $version;
        }
      }
    } else {
      self::$map = [ProtocolInfo::CURRENT_PROTOCOL => ProtocolInfo::MINECRAFT_VERSION];
    }
  }

  public static function getVersionFromProtocolId(int $protocolId): string
  {
    return self::$map[$protocolId] ?? ProtocolInfo::MINECRAFT_VERSION;
  }

  public static function getMap(): array
  {
    return self::$map;
  }

}