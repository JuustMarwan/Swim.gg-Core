<?php

namespace core\systems\player\components;

use core\custom\behaviors\player_event_behaviors\ParticleEmitter;
use core\database\SwimDB;
use core\systems\player\Component;
use core\systems\player\SwimPlayer;
use Generator;
use pocketmine\utils\TextFormat;
use poggit\libasynql\libs\SOFe\AwaitGenerator\Await;
use poggit\libasynql\SqlThread;

// mostly just examples of stuff, not fully used beyond from helper format messages for chat and rank strings
class Cosmetics extends Component
{

  private string $chatFormat = "";
  private string $killMessage = "{you} killed {other}";
  private string $tag = "";
  private string $hubParticleEffect = "";
  private string $selectedPet = "";
  private string $selectedHat = "";
  private string $selectedBackBling = "";

  private array $pets = array();
  private array $hats = array();
  private array $backBlings = array();

  public function refresh(): void
  {
    $this->setHubParticleEffect($this->hubParticleEffect); // this will recreate the particle emitter
  }

  public function formatTag(bool $space = true): string
  {
    if ($this->tag == "" || $this->swimPlayer->getNicks()->isNicked()) return "";

    $spacer = $space ? " " : "";
    return $spacer . TextFormat::GRAY . "[" . TextFormat::RESET . $this->tag . TextFormat::GRAY . "]";
  }

  // maybe name color shouldn't be bound to rank, but be a settable cosmetic
  public function getNameColor(): string
  {
    if ($this->swimPlayer->getNicks()->isNicked()) {
      return TextFormat::GRAY;
    }
    return Rank::getRankColor($this->swimPlayer->getRank()->getRankLevel());
  }

  public function tagNameTag(): void
  {
    if ($this->tag != "" && !$this->swimPlayer->getNicks()->isNicked()) {
      $this->swimPlayer->setNameTag(TextFormat::GRAY . "[" . $this->tag . TextFormat::GRAY . "] " . $this->getNameColor() . $this->swimPlayer->getName());
    } else {
      $this->swimPlayer->setNameTag($this->getNameColor() . $this->swimPlayer->getNicks()->getNick());
    }
  }

  public function killMessageLogic(SwimPlayer $killed): void
  {
    if ($this->shouldSendKillMessage()) {
      $this->core->getServer()->broadCastMessage($this->formatKillMessage($killed));
    }
  }

  public function formatKillMessage(SwimPlayer $killed): string
  {
    // Getting the color for the current player and the killed player.
    $color = $this->getNameColor();
    $killedColor = $killed->getCosmetics()->getNameColor();

    // Replacements - replacing placeholders with actual player names, prepended with their colors.
    $search = ["{you}", "{other}"];
    $replace = [TextFormat::RESET . $color . $this->swimPlayer->getName() . TextFormat::RESET, TextFormat::RESET . $killedColor . $killed->getNicks()->getNick() . TextFormat::RESET];

    // Returning the formatted kill message.
    return "» " . str_replace($search, $replace, $this->killMessage);
  }

  public function setTag(string $string): void
  {
    $this->tag = $string;
  }

  public function getTag(): string
  {
    return $this->tag;
  }

  public function shouldSendKillMessage(): bool
  {
    return !$this->swimPlayer->getNicks()->isNicked() && $this->killMessage !== "{you} killed {other}";
  }

  public function getChatFormat(): string
  {
    return $this->chatFormat == "" ? "white" : $this->chatFormat;
  }

  public function setChatFormat(string $chatFormat): void
  {
    $this->chatFormat = $chatFormat;
  }

  public function setKillMessage(string $message): void
  {
    $this->killMessage = $message;
  }

  public function getKillMessage(): string
  {
    return $this->killMessage;
  }

  public function getHubParticleEffect(): string
  {
    return $this->hubParticleEffect;
  }

  public function setHubParticleEffect(string $effect): void
  {
    $this->hubParticleEffect = $effect;
    $manager = $this->swimPlayer->getEventBehaviorComponentManager();
    $manager->removeComponent("particleEmitter");
    if ($this->hubParticleEffect != "") {
      $manager->registerComponent(new ParticleEmitter($this->core, $this->swimPlayer));
    }
  }

  // Retrieves all the cosmetic data for this player
  public function load(): Generator
  {
    $query = "SELECT * FROM Cosmetics WHERE xuid = ?";
    $xuid = $this->swimPlayer->getXuid();

    $rows = yield from Await::promise(fn($resolve, $reject) => SwimDB::getDatabase()->executeImplRaw(
      [0 => $query],
      [0 => [$xuid]],
      [0 => SqlThread::MODE_SELECT],
      $resolve,
      $reject
    ));

    // player must be connected to load data
    if ($this->swimPlayer->isConnected()) {
      if (isset($rows[0]->getRows()[0])) {
        $data = $rows[0]->getRows()[0];
        $this->tag = $data['tag'];
        $this->chatFormat = $data['chatFormat'];
        $this->killMessage = $data['killMessage'];
        $this->hubParticleEffect = $data['hubParticleEffect'];
        $this->selectedPet = $data['selectedPet'];
        $this->selectedHat = $data['selectedHat'];
        $this->selectedBackBling = $data['selectedBackBling'];
        $this->pets = json_decode($data['pets'], true);
        $this->hats = json_decode($data['hats'], true);
        $this->backBlings = json_decode($data['backBlings'], true);
      }
    }
  }

  // Saves all the cosmetic data for this player
  public function saveData(): void
  {
    $petsJson = json_encode($this->pets);
    $hatsJson = json_encode($this->hats);
    $backBlingsJson = json_encode($this->backBlings);

    $query = "
            INSERT INTO Cosmetics (xuid, name, chatFormat, tag, killMessage, hubParticleEffect, selectedPet, selectedHat, selectedBackBling, pets, hats, backBlings) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                chatFormat = VALUES(chatFormat),
                tag = VALUES(tag),
                killMessage = VALUES(killMessage),
                hubParticleEffect = VALUES(hubParticleEffect),
                selectedPet = VALUES(selectedPet),
                selectedHat = VALUES(selectedHat),
                selectedBackBling = VALUES(selectedBackBling),
                pets = VALUES(pets),
                hats = VALUES(hats),
                backBlings = VALUES(backBlings)";

    SwimDB::getDatabase()->executeImplRaw(
      [0 => $query],
      [0 => [
        $this->swimPlayer->getXuid(),
        $this->swimPlayer->getName(),
        $this->chatFormat,
        $this->tag,
        $this->killMessage,
        $this->hubParticleEffect,
        $this->selectedPet,
        $this->selectedHat,
        $this->selectedBackBling,
        $petsJson,
        $hatsJson,
        $backBlingsJson
      ]],
      [0 => SqlThread::MODE_GENERIC],
      function () {
      },
      null
    );
  }

}