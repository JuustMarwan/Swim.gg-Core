<?php

namespace core\systems\player\components\detections;

use core\commands\punish\PunishCmd;
use core\SwimCore;
use core\systems\player\components\AntiCheatData;
use core\systems\player\components\Rank;
use core\systems\player\SwimPlayer;
use core\utils\AcData;
use core\utils\cordhook\CordHook;
use core\utils\security\LoginProcessor;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\lang\Language;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

abstract class Detection
{

  protected float $numFlags = 0;
  protected int $totalHits = 0; // does not decay
  protected int $blockTime = 0;
  protected int $sleep = 0; // counts down by one each tick, while this is greater than 0 the detection is disabled

  protected string $kickMsg = "Error Code: Pickle Chin Ahh Boy";
  protected ?AntiCheatData $data;

  public function __construct
  (
    protected SwimCore   $swimCore,
    protected SwimPlayer $player,
    protected string     $name
  )
  {
  }

  // overridable
  public function init(): void
  {
    $this->data = $this->player->getAntiCheatData();
  }

  public function teleported(): void
  {
  }

  public function attacked(): void
  {
  }

  public function changedGameMode(): void
  {
  }

  abstract protected function isReliable(): bool;

  abstract protected function shouldLog(): bool;

  abstract protected function shouldKick(): bool;

  abstract protected function shouldBroadcastKick(): bool;

  abstract protected function getMaxFlags(): int;

  abstract protected function getDecayRate(): float;

  abstract protected function handlePacket(Packet $packet): bool;

  public function setSleepTicks(int $ticks): void
  {
    $this->sleep = $ticks;
    if ($this->sleep < 0) $this->sleep = 0;
  }

  /**
   * @return int
   */
  public function getSleepTicks(): int
  {
    return $this->sleep;
  }

  public function handle(DataPacketReceiveEvent $event): void
  {
    if ($this->sleep > 0) return;
    if (!$this->player->isConnected()) return;

    $this->handlePacket($event->getPacket()); // if this returns true we had it cancel the event, but that breaks things

    if ($this->getNumFlags(true) >= $this->getMaxFlags() && $this->shouldKick()) {
      $this->punish();
    }
  }

  protected function punish(string $reason = ""): void
  {
    if ($this->blockTime > 0) {
      $this->swimCore->getServer()->getNetwork()->blockAddress($this->player->getNetworkSession()->getIp(), $this->blockTime);
    }
    $this->BanPlayer($this->player, $this->swimCore); // ban them
    if ($this->shouldBroadcastKick()) {
      if ($reason == "") $reason = $this->name; // set reason to default name if needed
      self::PunishAlert($this->player, $this->swimCore, $reason);
    }
  }

  public static function PunishAlert(SwimPlayer $player, SwimCore $core, string $reason): void
  {
    if (!SwimCore::$AC) return; // won't punish if ac is disabled
    $kicked = TF::RED . "[BAN] " . TF::GREEN . "ANTICHEAT" . TF::WHITE . " Banned " . TF::GREEN . $player->getName() . TF::WHITE . ". Reason: " . TF::GREEN . $reason;
    CordHook::sendEmbed("Banned " . $player->getName() . " | " . $reason, "Microsoft AntiCheat BAN");
    self::StaffAlert($kicked, $core);
    $core->getServer()->broadcastMessage(TF::BOLD . TF::RED . "Microsoft AntiCheat has Removed " . TF::WHITE . $player->getName());
    $core->getLogger()->alert($kicked);
  }

  protected function reward(float $sub = 0.01): void
  {
    $this->numFlags = max($this->numFlags - $sub, 0);
  }

  // public static because there are other instance where this logic is needed
  public static function BanPlayer(Player $player, SwimCore $core): void
  {
    if (!SwimCore::$AC) return; // won't punish if ac is disabled
    if ($player instanceof SwimPlayer) {
      $nsl = $player->getNslHandler();
      $ping = $nsl->getPing();
      $jitter = $nsl->getJitter();
      $text = "(Ping: " . $ping . ", Jitter: " . $jitter . ")";
      $args = ["player" => $player->getName(), "severity" => 3, "reason" => "Banned by Microsoft AntiCheat " . $text];
      PunishCmd::punishmentLogic(new ConsoleCommandSender($core->getServer(), new Language(Language::FALLBACK_LANGUAGE)), "ban", $args, $core);
    }
  }

  public static function StaffAlert(string $message, SwimCore $core): void
  {
    // if ac is off then we just broadcast the message to everyone (debug state)
    if (!SwimCore::$AC) {
      $core->getServer()->broadcastMessage($message);
      return;
    }

    /* @var SwimPlayer[] $players */
    $players = $core->getServer()->getOnlinePlayers();
    foreach ($players as $player) {
      $rank = $player->getRank()?->getRankLevel();
      if ($rank >= Rank::HELPER_RANK) {
        $player->sendMessage($message);
      }
    }
  }

  public function tick(): void
  {
    // decay flags each tick
    if ($this->numFlags > 0) $this->numFlags -= $this->getDecayRate();

    // decay sleep ticks
    $this->sleep--;
    if ($this->sleep < 0) $this->sleep = 0;
  }

  public function getNumFlags(bool $int = false): float|int
  {
    return max(0, $int ? intval(round($this->numFlags)) : round($this->numFlags, 2));
  }

  public function addFlag(string $additional = ""): void
  {
    // if (!$this->swimCore->acOn) return;
    if (!$this->isReliable()) return;
    $this->numFlags++;
    $this->totalHits++;

    // we should also log if debug mode is on or the ac is flipped off into test mode
    if ($this->shouldLog() || SwimCore::$DEBUG || !SwimCore::$AC) {

      $input = $this->data->getData(AcData::INPUT_MODE) ?? -1; // we need to convert this to a string
      $os = $this->data->getData(AcData::DEVICE_OS) ?? "unknown";

      $inputString = "unknown";
      if ($input != -1 && isset(LoginProcessor::$inputModeMap[$input])) {
        $inputString = LoginProcessor::$inputModeMap[$input] ?? "unknown";
      }

      $nickPart = $this->player->getNicks()?->isNicked() ? " (" . $this->player->getNicks()->getNick() . ")" : "";
      $message = TF::RED . "[AC] " . TF::GREEN . $this->player->getName() . $nickPart . TF::WHITE . ": " . $this->name
        . " foul " . $this->getNumFlags(true) . " (ping: " . TF::GREEN . $this->player->getNslHandler()->getPing()
        . TF::WHITE . "ms, jitter: " . TF::GREEN . $this->player->getNslHandler()->getJitter() . TF::WHITE . "ms" . (($additional == "") ? "" : ", " . $additional) . ") "
        . $os . ", " . $inputString;

      // $this->swimCore->getLogger()->notice($message);
      $this->StaffAlert($message, $this->swimCore);

      // log to the discord
      $msg = $this->player->getName() . $nickPart . ": " . $this->name . " foul " . $this->getNumFlags(true) . " (ping: " . $this->player->getNslHandler()->getPing()
        . "ms, jitter: " . $this->player->getNslHandler()->getJitter() . "ms" . (($additional == "") ? "" : ", " . $additional) . ") " . $os . ", " . $inputString;
      CordHook::sendEmbed($msg, "Microsoft AntiCheat " . ucfirst($this->name) . " Flag");
    }
  }

}