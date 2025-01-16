<?php

namespace core\commands;

use core\scenes\hub\Hub;
use core\SwimCore;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use core\utils\TimeHelper;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class NickCmd extends Command
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct("nick", "type clear or reset if you want to reset your nick. MVP ranks can type anything else to set a specific nick");
    $this->core = $core;
    $this->setUsage("nick, clear|reset");
    $this->setPermission("use.all");
  }

  public function execute(CommandSender $sender, string $commandLabel, array $args): bool
  {
    if ($sender instanceof SwimPlayer) {
      // this should only be done in the hub or hubparty scenes
      if ($sender->isInScene("Hub")) {
        $rankLevel = $sender->getRank()->getRanklevel();
        if ($rankLevel < 1) {
          $sender->sendMessage(TextFormat::YELLOW . "You do not have perms to set your nick name! Buy a rank at "
            . TextFormat::GREEN . "swim.tebex.io " . TextFormat::YELLOW . "or boost " . TextFormat::LIGHT_PURPLE . "discord.gg/swim");
        } else {
          // if we have perms then check if clearing or generating a new name tag
          if (isset($args[0]) && ($args[0] == 'clear' || $args[0] == 'reset')) { // resetting
            $sender->getNicks()->resetNick();
            $sender->getCosmetics()->tagNameTag();
            $sender->sendMessage(TextFormat::GREEN . "Reset your nickname back to your real name!");
            $this->staffAlert($sender);
            Hub::setHubTags($sender);
          } elseif (isset($args[0])) { // directly setting
            if ($rankLevel >= Rank::OWNER_RANK) {
              $name = $args[0];
              $lower = strtolower($name);

              if (!$this->timeCheck($rankLevel, $sender)) return false;

              // length check
              if (strlen($name) > 12) {
                $sender->sendMessage(TextFormat::RED . "That nick is too long!");
                return false;
              }

              // check legality
              $disallowedNames = ["swedeachu", "gameparrot", "gxmeparrot", "swimfan"];
              foreach ($disallowedNames as $disallowedName) {
                if (str_contains($lower, $disallowedName)) {
                  $sender->sendMessage(TextFormat::RED . "You cannot nick as another player on the server");
                  return false;
                }
              }

              // check online legality
              foreach ($this->core->getServer()->getOnlinePlayers() as $player) {
                if ($player instanceof SwimPlayer && (strtolower($player->getName()) == $lower || ($player->getNicks()?->getNick() ?? "") == $lower)) {
                  $sender->sendMessage(TextFormat::RED . "You can not nick as another player on the server");
                  return false;
                }
              }

              // set name tag
              $sender->getNicks()->setNickTo($name);
              $sender->getCosmetics()->tagNameTag();
              $sender->setNameTag(TextFormat::GRAY . $sender->getNicks()->getNick());
              $this->staffAlert($sender);
              Hub::setHubTags($sender);
            } else {
              // $sender->sendMessage(TextFormat::RED . "You need MVP to set a specific nick: " . TextFormat::AQUA . "swim.tebex.io");
              $sender->sendMessage(TextFormat::RED . "Setting nick directly has been disabled");
            }
          } else { // randomly setting
            if (!$this->timeCheck($rankLevel, $sender)) return false;
            $sender->getNicks()->setRandomNick();
            $sender->setNameTag(TextFormat::GRAY . $sender->getNicks()->getNick());
            $this->staffAlert($sender);
            Hub::setHubTags($sender);
          }
        }
      } else {
        $sender->sendMessage(TextFormat::RED . "You can only use this command in the Hub!");
      }
    }
    return true;
  }

  private function timeCheck(int $rankLevel, SwimPlayer $sender): bool
  {
    if ($rankLevel < Rank::MOD_RANK) {
      // Calculate the time difference in ticks
      $difference = $this->core->getServer()->getTick() - $sender->getNicks()->getLastNickTick();

      // Convert the required wait time to ticks
      $requiredWaitTicks = TimeHelper::secondsToTicks(30);

      // Check if the difference is less than the required wait time
      if ($difference < $requiredWaitTicks) {
        // Calculate the remaining time in seconds
        $remainingTime = TimeHelper::ticksToSeconds($requiredWaitTicks - $difference);
        $sender->sendMessage(TextFormat::RED . "You must wait " . $remainingTime . " more seconds before nicking again");
        return false;
      }
    }
    return true;
  }


  private function staffAlert(SwimPlayer $plr): void
  {
    $level = $plr->getRank()->getRankLevel();
    $message = TextFormat::AQUA . $plr->getName() . " nicked as " . $plr->getNicks()->getNick();

    foreach ($this->core->getServer()->getOnlinePlayers() as $player) {
      if ($plr instanceof SwimPlayer) {
        // don't message self
        if ($player === $plr) continue;
        // can see other messages if their rank level is at least helper and their rank is higher than both of the messagers
        $rank = $plr->getRank()->getRankLevel();
        if ($rank >= Rank::HELPER_RANK && $rank > $level) {
          $player->sendMessage($message);
        }
      }
    }
  }

}