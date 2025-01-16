<?php

namespace core\forms\parties;

use core\SwimCore;
use core\systems\party\Party;
use core\systems\player\SwimPlayer;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\utils\TextFormat;

class FormPartySettings
{

  public static function baseSelection(SwimCore $core, SwimPlayer $player, Party $party): void
  {
    $form = new SimpleForm(function (SwimPlayer $player, int $data = null) use ($core, $party) {
      if ($data === null) return;

      switch ($data) {
        case 0:
          self::partyPvpSettingsForm($core, $player, $party);
          break;

        case 1:
          self::partySettingsForm($core, $player, $party);
          break;
      }
    });

    $form->setTitle(TextFormat::GREEN . "Party Settings");
    $form->addButton(TextFormat::GREEN . "PvP Settings");
    $form->addButton(TextFormat::GREEN . "General Party Settings");

    $player->sendForm($form);
  }

  public static function partyPvpSettingsForm(SwimCore $core, SwimPlayer $player, Party $party): void
  {
    $form = new CustomForm(function (SwimPlayer $player, array $data = null) use ($core, $party) {
      if ($data === null) return;

      // Apply new PVP settings to the Party object, translating the integer values to decimal
      $var = -1;
      $party->vertKB = $data[++$var] / 100.0; // Translating 0-100 scale to 0.0-1.0
      $party->horKB = $data[++$var] / 100.0;
      $party->controllerVertKB = $data[++$var] / 100.0;
      $party->controllerKB = $data[++$var] / 100.0;
      $party->hitCoolDown = (int)$data[++$var]; // Hit Cool down remains between 0 and 20
      $party->pearlKB = $data[++$var] / 100.0;
      $party->snowballKB = $data[++$var] / 100.0;
      $party->rodKB = $data[++$var] / 100.0;
      $party->arrowKB = $data[++$var] / 100.0;
      $party->pearlSpeed = $data[++$var] / 10.0; // Translating 0-40 to 0.0-4.0
      $party->pearlGravity = $data[++$var] / 100.0; // Translating 0-20 scale to 0.0-0.2
      $party->naturalRegen = (bool)$data[++$var];
      $party->fallDamage = (bool)$data[++$var];

      // Send confirmation to the player
      $player->sendMessage(TextFormat::GREEN . "Party PvP settings have been updated by " . TextFormat::YELLOW . $player->getNicks()->getNick());
    });

    $form->setTitle(TextFormat::GREEN . "Party PvP Settings");

    // Add sliders for the knockback and other float-based settings
    $form->addSlider("Vertical Knockback", 0, 100, 1, (int)($party->vertKB * 100));
    $form->addSlider("Horizontal Knockback", 0, 100, 1, (int)($party->horKB * 100));
    $form->addSlider("Controller Vertical Knockback", 0, 100, 1, (int)($party->controllerVertKB * 100));
    $form->addSlider("Controller Horizontal Knockback", 0, 100, 1, (int)($party->controllerKB * 100));

    // Hit cool down is between 0 and 20 ticks
    $form->addSlider("Hit Cooldown (ticks)", 0, 20, 1, $party->hitCoolDown);

    $form->addSlider("Ender Pearl Knockback", 0, 100, 1, (int)($party->pearlKB * 100));
    $form->addSlider("Snowball Knockback", 0, 100, 1, (int)($party->snowballKB * 100));
    $form->addSlider("Fishing Rod Knockback", 0, 100, 1, (int)($party->rodKB * 100));
    $form->addSlider("Arrow Knockback", 0, 100, 1, (int)($party->arrowKB * 100));

    // Pearl speed is now between 0 and 40, to represent 0.0 - 4.0 after translation
    $form->addSlider("Ender Pearl Speed", 0, 40, 1, (int)($party->pearlSpeed * 10)); // 0-40 scale to 0.0-4.0
    $form->addSlider("Ender Pearl Gravity", 0, 20, 1, (int)($party->pearlGravity * 100)); // 0-20 scale to 0.0-0.2

    // Add toggles for the boolean settings
    $form->addToggle("Natural Health Regeneration", $party->naturalRegen);
    $form->addToggle("Fall Damage Enabled", $party->fallDamage);

    $form->addLabel(TextFormat::YELLOW . "THESE SETTINGS ONLY IMPACT DUELS WITH YOUR OWN PARTY!");

    // Send the form to the player
    $player->sendForm($form);
  }

  public static function partySettingsForm(SwimCore $core, SwimPlayer $player, Party $party): void
  {
    $form = new CustomForm(function (SwimPlayer $player, array $data = null) use ($core, $party) {
      if ($data === null) return;

      // apply party name if was changed
      // $name = $data[0];
      // $partyName = $party->getPartyName();
      // $partySystem = $core->getSystemManager()->getPartySystem();

      /*
      if ($name != $partyName) {
        if ($partySystem->partyNameTaken($name)) {
          $player->sendMessage(TextFormat::RED . "We can not change the party name to this, it is already taken by another party!");
        } else {
          $party->setPartyName($name);
          $party->partyMessage(TextFormat::GREEN . "Your party name was changed to " . TextFormat::YELLOW . $name);
        }
      }
      */

      // apply new settings
      $var = -1;
      $party->setSetting('random', $data[++$var]);
      $party->setSetting('allowDuelInvites', $data[++$var]);
      $party->setSetting('allowJoinRequests', $data[++$var]);
      $party->setSetting('openJoin', $data[++$var]);
      $party->setSetting('membersCanInvite', $data[++$var]);
      $party->setSetting('membersCanAllowJoin', $data[++$var]);
      $party->setSetting('membersCanQueue', $data[++$var]);
      $party->setSetting('membersCanDuel', $data[++$var]);
      $party->setSetting('membersCanAcceptDuel', $data[++$var]);

      // send a message to the player confirming the changes
      $player->sendMessage(TextFormat::GREEN . "Party settings have been updated.");

      // re-kit the party members with the new party control items based on the new settings
      $party->setHubKits();
    });

    $form->setTitle(TextFormat::GREEN . "Party Settings");
    // $form->addInput("Party Name", $party->getPartyName(), $party->getPartyName());
    $form->addToggle("Randomize Teams for Self Duels", $party->getSetting('random'));
    $form->addToggle("Allow Duel Requests", $party->getSetting('allowDuelInvites'));
    $form->addToggle("Allow Join Requests", $party->getSetting('allowJoinRequests'));
    $form->addToggle("Open Join", $party->getSetting('openJoin'));
    $form->addToggle("Members can Invite", $party->getSetting('membersCanInvite'));
    $form->addToggle("Members can Accept Join Invites", $party->getSetting('membersCanAllowJoin'));
    $form->addToggle("Members can Queue", $party->getSetting('membersCanQueue'));
    $form->addToggle("Members can Duel Request", $party->getSetting('membersCanDuel'));
    $form->addToggle("Members can Accept Duels", $party->getSetting('membersCanAcceptDuel'));

    $player->sendForm($form);
  }

}