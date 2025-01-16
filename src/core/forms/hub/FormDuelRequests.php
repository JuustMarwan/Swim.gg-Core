<?php

namespace core\forms\hub;

use core\scenes\duel\Boxing;
use core\scenes\duel\Duel;
use core\scenes\duel\IconHelper;
use core\scenes\duel\Midfight;
use core\scenes\duel\Nodebuff;
use core\scenes\hub\Queue;
use core\SwimCore;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use jackmd\scorefactory\ScoreFactoryException;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\utils\TextFormat;

class FormDuelRequests
{

  public static string $adMsg = TextFormat::DARK_AQUA . "Buy a rank on " .
  TextFormat::AQUA . "swim.tebex.io" .
  TextFormat::DARK_AQUA . " or boost " .
  TextFormat::LIGHT_PURPLE . "discord.gg/swim" .
  TextFormat::DARK_AQUA . " to pick duel maps!";

  public static function duelSelectionBase(SwimCore $core, SwimPlayer $swimPlayer): void
  {
    if (!$swimPlayer->isInScene("Hub")) return;

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core) {
      if ($data === null) {
        return;
      }

      if (!$player->isInScene("Hub")) return;

      if ($data == 0) {
        self::viewDuelRequests($core, $player);
      } elseif ($data == 1) {
        self::viewPossibleOpponents($core, $player);
      } else {
        $player->sendMessage(TextFormat::RED . "Error");
      }
    });

    $form->setTitle(TextFormat::DARK_GREEN . "Duel Manager");

    // make buttons
    $form->addButton(TextFormat::RED . "View Duel Requests " . TextFormat::DARK_GRAY . "["
      . TextFormat::AQUA . count($swimPlayer->getInvites()->getDuelInvites()) . TextFormat::DARK_GRAY . "]");
    $form->addButton(TextFormat::DARK_GREEN . "Send a Duel Request");

    $swimPlayer->sendForm($form);
  }

  private static function viewDuelRequests(SwimCore $core, SwimPlayer $swimPlayer): void
  {
    if (!$swimPlayer->isInScene("Hub")) return;

    $buttons = [];

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, &$buttons) {
      if ($data === null) return;

      if (!$player->isInScene("Hub")) return;

      // First fetch sender name
      $senders = array_keys($buttons);
      if (isset($senders[$data])) {
        // get as player
        $sender = $senders[$data];
        $senderPlayer = $core->getServer()->getPlayerExact($sender);
        if ($senderPlayer instanceof SwimPlayer) {
          // check if this sender is in the hub and the mode has an available map
          $inviteData = $buttons[$sender];
          if ($senderPlayer->isInScene("Hub")) {
            if ($core->getSystemManager()->getMapsData()->modeHasAvailableMap($inviteData['mode'])) {
              self::startDuel($core, $senderPlayer, $player, $inviteData);
            } else {
              $player->sendMessage(TextFormat::RED . "No map is currently available for that mode, try again later");
            }
            return;
          }
        }
      }

      $player->sendMessage(TextFormat::RED . "Duel Expired");
    });

    $form->setTitle("Duel Requests");

    // make buttons from requests
    $requests = $swimPlayer->getInvites()->getDuelInvites();
    foreach ($requests as $sender => $inviteData) {
      $buttons[$sender] = $inviteData;
      $mode = $inviteData['mode'];
      $map = $inviteData['map'];
      $text = TextFormat::DARK_GREEN . $sender . TextFormat::GRAY . " | " . TextFormat::RED . ucfirst($mode) . TextFormat::GRAY . " | " . TextFormat::YELLOW . "Map: " . ucfirst($map);
      $form->addButton($text, 0, IconHelper::getIcon($mode));
    }

    $swimPlayer->sendForm($form);
  }

  /**
   * @throws ScoreFactoryException
   */
  private static function startDuel(SwimCore $core, SwimPlayer $user, SwimPlayer $inviter, $inviteData): void
  {
    // insanely based method to get the queue scene and use one of its functions to start a duel that way
    $queue = $core->getSystemManager()->getSceneSystem()->getScene('Queue');
    if ($queue instanceof Queue) {
      $queue->publicDuelStart($user, $inviter, $inviteData['mode'], $inviteData['map']);
    }
  }

  // get all players in hub scene with duel invites on
  private static function viewPossibleOpponents(SwimCore $core, SwimPlayer $swimPlayer): void
  {
    if (!$swimPlayer->isInScene("Hub")) return;

    $buttons = [];

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, &$buttons) {
      if ($data === null) return;

      if (!$player->isInScene("Hub")) return;

      // Fetch Swim Player from button
      if (isset($buttons[$data])) {
        $playerToDuel = $buttons[$data];
        if ($playerToDuel instanceof SwimPlayer) {
          self::duelSelection($core, $player, $playerToDuel);
          return;
        }
      }

      $player->sendMessage(TextFormat::RED . "Error");
    });

    $form->setTitle(TextFormat::DARK_GREEN . "Choose an Opponent");

    // get the array of swim players in the hub
    $players = $core->getSystemManager()->getSceneSystem()->getScene("Hub")->getPlayers();

    $id = $swimPlayer->getId();
    foreach ($players as $plr) {
      if ($plr instanceof SwimPlayer) {
        // skip self if not in debug mode
        if ($plr->getId() != $id || SwimCore::$DEBUG) {
          if ($plr->getSettings()->getToggle('duelInvites')) {
            $buttons[] = $plr;
            $form->addButton($plr->getRank()->rankString());
          }
        }
      }
    }

    $swimPlayer->sendForm($form);
  }

  private static function duelSelection(SwimCore $core, SwimPlayer $user, SwimPlayer $invited): void
  {
    if (!$user->isInScene("Hub")) return;

    $modes = Duel::$MODES;

    // Create the simple form
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, $invited, $user, $modes) {
      if ($data === null) {
        return; // Form closed or no input
      }

      if (!$player->isInScene("Hub")) return;

      // attempt to fix stale invite crash
      if(!(isset($invited) && isset($user) && $invited->isOnline() && $user->isOnline())) return;

      // Check if the selected button corresponds to a valid game mode
      if (isset($modes[$data])) {
        $mode = $modes[$data];

        // Check the rank of the user and proceed accordingly
        $rankLevel = $user->getRank()->getRankLevel();
        if ($rankLevel > Rank::DEFAULT_RANK) {
          // Higher-ranked users get to select a map from the mode
          self::selectMapForMode($core, $user, $invited, $mode);
        } else {
          // Default rank users proceed with a random map
          $invited->getInvites()?->duelInvitePlayer($player, $mode);
          $player->sendMessage(self::$adMsg);
        }
      } else {
        $player->sendMessage(TextFormat::RED . "Error: Invalid game mode selected.");
      }
    });

    // Set the title of the form
    $form->setTitle(TextFormat::DARK_GREEN . "Select Game Mode");

    // Add buttons for each game mode with corresponding icons
    $form->addButton("§4Nodebuff", 0, Nodebuff::getIcon());
    $form->addButton("§4Boxing", 0, Boxing::getIcon());
    $form->addButton("§4Midfight", 0, Midfight::getIcon());

    // Send the form to the user
    $user->sendForm($form);
  }

  private static function selectMapForMode(SwimCore $core, SwimPlayer $user, SwimPlayer $invited, string $mode): void
  {
    $mapsData = $core->getSystemManager()->getMapsData();

    // Fetch the map pool based on the mode
    $basic = false;
    $mapPool = match ($mode) {
      default => $mapsData->getBasicDuelMaps(),
      'misc' => $mapsData->getMiscDuelMaps(), // unused/unreachable
    };

    if ($mapPool === null) {
      $user->sendMessage(TextFormat::RED . "No maps available for this mode.");
      return;
    }

    // if addresses of the picked map pool and basic map pool are the same, then set basic flag to true
    if ($mapPool === $mapsData->getBasicDuelMaps()) $basic = true;

    // Get the unique map base names
    $uniqueMapNames = $mapPool->getUniqueMapBaseNames();
    // if we are basic, that means we are using misc maps as well
    if ($basic) {
      $misc = $mapsData->getMiscDuelMaps();
      $uniqueMiscNames = $misc->getUniqueMapBaseNames();
      // push back into the array to combine
      foreach ($uniqueMiscNames as $name) $uniqueMapNames[] = $name;
    }

    if (empty($uniqueMapNames)) {
      $user->sendMessage(TextFormat::RED . "No maps available for this mode.");
      return;
    }

    // Prepare the map selection form
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, $invited, $mode, $mapPool, $uniqueMapNames) {
      if ($data === null) return; // Form closed or no input

      if (isset($uniqueMapNames[$data])) {
        // Get the first inactive map that starts with the selected base name
        $selectedBaseName = $uniqueMapNames[$data];
        $selectedMap = $mapPool->getFirstInactiveMapByBaseName($selectedBaseName);
        if ($selectedMap !== null) {
          $invited->getInvites()->duelInvitePlayer($player, $mode, $selectedBaseName);
        } else {
          $player->sendMessage(TextFormat::RED . "ERROR: Try again later. No available map found for " . $selectedBaseName);
        }
      } else {
        // If no map is selected, default to 'random'
        $invited->getInvites()->duelInvitePlayer($player, $mode);
      }
    });

    // Add the "random" button as the first option
    $form->setTitle(TextFormat::DARK_GREEN . "Select a Map");
    $form->addButton(TextFormat::DARK_GREEN . "Random", 0, "", "random");

    // Add buttons for each unique map base name
    foreach ($uniqueMapNames as $index => $baseName) {
      $form->addButton(TextFormat::DARK_RED . ucfirst($baseName), 0, "", $index);
    }

    // Send the form to the user
    $user->sendForm($form);
  }

}