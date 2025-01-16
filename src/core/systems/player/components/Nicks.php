<?php

namespace core\systems\player\components;

use core\SwimCore;
use core\systems\player\Component;
use core\systems\player\SwimPlayer;
use core\utils\cordhook\CordHook;
use core\utils\Words;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class Nicks extends Component
{

  private string $nick;
  private bool $hasNick;

  private int $lastNickTick = 0;

  public function getLastNickTick(): int
  {
    return $this->lastNickTick;
  }

  public function __construct(SwimCore $core, SwimPlayer $swimPlayer)
  {
    parent::__construct($core, $swimPlayer);
    $this->hasNick = false;
    $this->resetNick();
  }

  public function getNick(): string
  {
    return $this->nick;
  }

  public function isNicked(): bool
  {
    return $this->hasNick;
  }

  public function resetNick(): void
  {
    $this->hasNick = false;
    $this->nick = $this->swimPlayer->getName();
    $this->swimPlayer->setDisplayName($this->nick);
    $this->syncPlayerList();
  }

  public function setNickTo(string $name): void
  {
    $this->hasNick = true;
    $this->nick = $name;
    $this->swimPlayer->setDisplayName($this->nick);
    $this->syncPlayerList();
    $this->swimPlayer->sendMessage(TextFormat::GREEN . "Set your nick to " . TextFormat::YELLOW . $this->nick);
    CordHook::sendEmbed($this->swimPlayer->getName() . " set nick to " . $this->nick, "Nick Alert");
    $this->lastNickTick = $this->core->getServer()->getTick();
  }

  // sets a randomly generated nick
  public function setRandomNick(): void
  {
    $longestName = 12;
    do {
      $nameType = rand(1, 3);
      if ($nameType == 1) {
        $name = Words::$adjectives[array_rand(Words::$adjectives)] . ucfirst(Words::$animals[array_rand(Words::$animals)]) . rand(1, 99);
      } else if ($nameType == 2) {
        $name = Words::$nouns[array_rand(Words::$nouns)] . Words::$names[ucfirst(array_rand(Words::$names))];
      } else {
        $name = Words::$verbs[array_rand(Words::$verbs)] . Words::$nouns[ucfirst(array_rand(Words::$nouns))] . rand(1, 99);
      }
    } while (strlen($name) > $longestName);

    $lowerAll = rand(0, 1); // 50% chance to lower all
    if ($lowerAll) $name = strtolower($name);
    $ucFirst = rand(0, 1); // 50% chance to then uppercase the first letter
    if ($ucFirst) $name = ucfirst($name);
    $upAll = rand(0, 4); // 1 in 5 chance of being upper case
    if ($upAll == 0) $name = strtoupper($name);

    $shouldPrepend = rand(0, 3); // chance of prepending an 'x' or a 'z' or an 'its' or an 'itz' to look gamer style if we have a name that isn't too long
    $len = strlen($name);
    if ($shouldPrepend == 0 && $len < $longestName) {
      $pre = rand(0, 1) ? "x" : "z";
      $name = $pre . ucfirst($name);
    } else if ($shouldPrepend == 1 && $len < 9) {
      $pre = rand(0, 1) ? "its" : "itz";
      $upper = rand(0, 1);
      if ($upper) $pre = ucfirst($pre);
      $name = $pre . ucfirst($name);
    }

    $this->hasNick = true;
    $this->nick = $name;
    $this->swimPlayer->setDisplayName($this->nick);
    $this->syncPlayerList();
    $this->swimPlayer->sendMessage(TextFormat::GREEN . "Set your nick to " . TextFormat::YELLOW . $this->nick);
    $this->lastNickTick = $this->core->getServer()->getTick();
    CordHook::sendEmbed($this->swimPlayer->getName() . " set nick to " . $this->nick, "Nick Alert");
  }

  private function syncPlayerList(): void
  {
    $pk = PlayerListPacket::add([
      PlayerListEntry::createAdditionEntry($this->swimPlayer->getUniqueId(), $this->swimPlayer->getId(), $this->swimPlayer->getDisplayName(),
        TypeConverter::getInstance()->getSkinAdapter()->toSkinData($this->swimPlayer->getSkin()), $this->swimPlayer->getXuid())
    ]);
    NetworkBroadcastUtils::broadcastPackets(Server::getInstance()->getOnlinePlayers(), [$pk]);
  }

}
