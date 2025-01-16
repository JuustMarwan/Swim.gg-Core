<?php

namespace core\forms\parties;

use core\scenes\duel\Boxing;
use core\scenes\duel\Duel;
use core\scenes\duel\Midfight;
use core\scenes\duel\Nodebuff;
use core\SwimCore;
use core\SwimCoreInstance;
use core\systems\party\Party;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\utils\TextFormat;

class FormPartyDuels
{

  public static function baseForm(SwimCore $core, SwimPlayer $swimPlayer, Party $party): void
  {
    $form = new SimpleForm(function (SwimPlayer $swimPlayer, $data) use ($core, &$buttons, $party) {
      if ($data === null) return;

      if (!$party->isInDuel()) {
        switch ($data) {
          case 0:
            self::selfPartyDuel($core, $swimPlayer, $party);
            break;
          case 1:
            // party mini-games button disguised for scrims right now when owner
            if ($swimPlayer->getRank()->getRankLevel() >= Rank::OWNER_RANK) {
              self::selectMapForMode($core, $swimPlayer, $party, "scrim", true);
              // TODO: have a form for inviting another party to a scrim duel (form party invite)
            }
            break;
        }
      }
    });

    $form->setTitle(TextFormat::DARK_PURPLE . "Select Mode");
    $form->addButton(TextFormat::DARK_AQUA . "Duel own Party");
    $form->addButton(TextFormat::YELLOW . "COMING SOON | Party Mini Games");
    $swimPlayer->sendForm($form);
  }

  private static function selfPartyDuel(SwimCore $core, SwimPlayer $swimPlayer, Party $party): void
  {
    if ($party->getCurrentPartySize() <= 1 && !SwimCore::$DEBUG) {
      $swimPlayer->sendMessage(TextFormat::RED . "You need at least 2 people in the party to start a duel!");
      return;
    }

    $modes = Duel::$MODES;

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, $party, $swimPlayer, $modes) {
      if ($data === null || $party === null) {
        return;
      }

      // Check if the party size is still valid
      if ($party->getCurrentPartySize() <= 1 && !SwimCore::$DEBUG) {
        $player->sendMessage(TextFormat::RED . "You need at least 2 people in the party to start a duel!");
        return;
      }

      $mode = $modes[$data] ?? null;

      if (isset($mode) && !$party->isInDuel()) {
        // Check the rank level of the party leader
        $rankLevel = $swimPlayer->getRank()->getRankLevel();
        if ($rankLevel > Rank::DEFAULT_RANK) {
          // Allow map selection
          self::selectMapForMode($core, $player, $party, $mode, true);
        } else {
          // Proceed with a random map and send an advertisement message
          if ($core->getSystemManager()->getMapsData()->modeHasAvailableMap($mode)) {
            $party->startSelfDuel($mode);
            // $player->sendMessage(FormDuelRequests::$adMsg);
          } else {
            $player->sendMessage(TextFormat::RED . "No map is currently available for that mode, try again later");
          }
        }
      }
    });

    $form->setTitle(TextFormat::GREEN . "Select Game");
    $form->addButton("§4Nodebuff", 0, Nodebuff::getIcon());
    $form->addButton("§4Boxing", 0, Boxing::getIcon());
    $form->addButton("§4Midfight", 0, Midfight::getIcon());
    $swimPlayer->sendForm($form);
  }

  public static function pickOtherPartyToDuel(SwimCore $core, SwimPlayer $player, Party $party): void
  {
    $buttons = [];

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, &$buttons, $party) {
      if ($data === null) return;

      $partyNames = array_keys($buttons);
      if (!isset($partyNames[$data])) return;
      $partyName = $partyNames[$data];
      if (!isset($buttons[$partyName])) return;
      $otherParty = $buttons[$partyName];

      if ($otherParty instanceof Party) {
        if (!$otherParty->isInDuel() && $otherParty->getSetting('allowDuelInvites')) {
          self::sendPartyDuelRequest($core, $player, $party, $otherParty);
        } else {
          $player->sendMessage(TextFormat::RED . "Party no longer available to duel");
        }
      }
    });

    // add parties to the buttons
    foreach ($core->getSystemManager()->getPartySystem()->getParties() as $partyName => $p) {
      if ($p instanceof Party) {
        if (!$p->isInDuel() && $p->canAddPlayerToParty() && $p->getSetting('allowDuelInvites') && $party !== $p) {
          $buttons[$partyName] = $p;
          // $label = $openJoin ? "Open to Join" : "Request to Join"; // not sure what label even is (sub text?)
          $form->addButton($partyName . TextFormat::GRAY . " | " . $p->formatSize());
        }
      }
    }

    $form->setTitle(TextFormat::LIGHT_PURPLE . "Parties Available to Duel");

    $player->sendForm($form);
  }

  private static function sendPartyDuelRequest(SwimCore $core, SwimPlayer $player, Party $senderParty, Party $otherParty): void
  {
    $modes = Duel::$MODES;

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, $senderParty, $otherParty, $modes) {
      if ($data === null) {
        return;
      }

      $mode = $modes[$data] ?? null;

      if (isset($mode) && !$otherParty->isInDuel() && !$senderParty->isInDuel()) {
        // Check the rank level of the player
        $rankLevel = $player->getRank()->getRankLevel();
        if ($rankLevel > Rank::DEFAULT_RANK) {
          // Allow map selection
          self::selectMapForMode($core, $player, $senderParty, $mode, false, $otherParty);
        } else {
          // Proceed with a random map and send an advertisement message
          $otherParty->duelInvite($player, $senderParty, $mode);
          // $player->sendMessage(FormDuelRequests::$adMsg);
        }
      }
    });

    $form->setTitle(TextFormat::GREEN . "Select Game");
    $form->addButton("§4Nodebuff", 0, Nodebuff::getIcon());
    $form->addButton("§4Boxing", 0, Boxing::getIcon());
    $form->addButton("§4Midfight", 0, Midfight::getIcon());
    $player->sendForm($form);
  }

  private static function selectMapForMode(SwimCore $core, SwimPlayer $player, Party $party, string $mode, bool $isSelfDuel, ?Party $otherParty = null): void
  {
    $mapsData = $core->getSystemManager()->getMapsData();

    // Fetch the map pool based on the mode
    $basic = false;
    $mapPool = match ($mode) {
      default => $mapsData->getBasicDuelMaps(),
      'misc' => $mapsData->getMiscDuelMaps(),
    };

    if ($mapPool === null) {
      $player->sendMessage(TextFormat::RED . "No maps available for this mode.");
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
      $player->sendMessage(TextFormat::RED . "No maps available for this mode.");
      return;
    }

    // Prepare the map selection form
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($core, $party, $mode, $isSelfDuel, $otherParty, $uniqueMapNames) {
      if ($data === null) return;

      if (isset($uniqueMapNames[$data])) {
        // Get the selected base name
        $selectedBaseName = $uniqueMapNames[$data];
        $selectedMap = $core->getSystemManager()->getMapsData()->getFirstInactiveMapByBaseNameFromMode($mode, $selectedBaseName);
        if ($selectedMap !== null) {
          if ($isSelfDuel) {
            $party->startSelfDuel($mode, $selectedBaseName);
          } else {
            $otherParty->duelInvite($player, $party, $mode, $selectedBaseName);
          }
        } else {
          $player->sendMessage(TextFormat::RED . "ERROR: Try again later. No available map found for " . $selectedBaseName);
        }
      } else {
        // If no map is selected, default to 'random'
        if ($isSelfDuel) {
          $party->startSelfDuel($mode); // random map
        } else {
          $otherParty->duelInvite($player, $party, $mode); // random map
        }
      }
    });

    // Add the "Random" button as the first option
    $form->setTitle(TextFormat::DARK_GREEN . "Select a Map");
    $form->addButton(TextFormat::DARK_GREEN . "Random", 0, "", "random");

    // Add buttons for each unique map base name
    foreach ($uniqueMapNames as $index => $baseName) {
      $form->addButton(TextFormat::DARK_RED . ucfirst($baseName), 0, "", $index);
    }

    // Send the form to the player
    $player->sendForm($form);
  }

  public static function acceptPartyDuelRequests(SwimPlayer $player, Party $party): void
  {
    $buttons = [];

    $form = new SimpleForm(function (SwimPlayer $player, $data) use (&$buttons, $party) {
      if ($data === null) return;

      $partyNames = array_keys($buttons);
      if (!isset($partyNames[$data])) return;
      $partyName = $partyNames[$data];
      if (!isset($buttons[$partyName])) return;
      $partyData = $buttons[$partyName];

      $otherParty = $partyData['party'];
      $mode = $partyData['mode'];
      $mapName = $partyData['map'] ?? 'random';

      if ($otherParty instanceof Party) {
        if (!$otherParty->isInDuel()) {
          if (SwimCoreInstance::getInstance()->getSystemManager()->getMapsData()->modeHasAvailableMap($mode)) {
            $party->startPartyVsPartyDuel($otherParty, $mode, $mapName);
          } else {
            $player->sendMessage(TextFormat::RED . "No map is currently available for that mode, try again later");
          }
        } else {
          $player->sendMessage(TextFormat::RED . "Party no longer available to duel");
        }
      }
    });

    // Add parties to the buttons
    foreach ($party->getDuelRequests() as $text => $partyData) {
      if (!$party->isInDuel()) {
        $buttons[$text] = $partyData; // maybe a todo is to make the party data also include map name on it
        $form->addButton($text . TextFormat::DARK_GRAY . " | " . $partyData['party']->formatSize());
      }
    }

    $form->setTitle(TextFormat::LIGHT_PURPLE . "Party Duel Requests");
    $player->sendForm($form);
  }

}