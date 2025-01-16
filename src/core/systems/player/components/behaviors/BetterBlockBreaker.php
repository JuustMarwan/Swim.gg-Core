<?php

namespace core\systems\player\components\behaviors;

use core\SwimCore;
use core\systems\player\SwimPlayer;
use pocketmine\block\Block;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\world\particle\BlockPunchParticle;
use pocketmine\world\sound\BlockPunchSound;

class BetterBlockBreaker
{

  public const DEFAULT_FX_INTERVAL_TICKS = 5;
  private int $fxTicker = 0;
  private float $breakSpeed;
  private float $breakProgress = 0;
  private bool $clientAttemptedBreakEarly = false;

  public function __construct(
    private readonly SwimPlayer $swimPlayer,
    // private SwimCore             $core,
    private readonly Vector3    $blockPos,
    private readonly Block      $block,
    private int                 $targetedFace,
    private readonly int        $maxPlayerDistance,
    private readonly int        $fxTickInterval = self::DEFAULT_FX_INTERVAL_TICKS
  )
  {
    // parent::__construct("blockbreaker", $core, $swimPlayer, true, false, 120);

    $this->breakSpeed = $this->calculateBreakProgressPerTick();
    if ($this->breakSpeed > 0) {
      $this->swimPlayer->getWorld()->broadcastPacketToViewers(
        $this->blockPos,
        LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int)(65535 * $this->breakSpeed), $this->blockPos)
      );
    }
  }

  /**
   * Returns the calculated break speed as percentage progress per game tick.
   */
  private function calculateBreakProgressPerTick(): float
  {
    if (!$this->block->getBreakInfo()->isBreakable()) {
      return 0.0;
    }

    $breakTimePerTick = $this->block->getBreakInfo()->getBreakTime($this->swimPlayer->getInventory()->getItemInHand()) * 20;
    if (!$this->swimPlayer->isOnGround() && !$this->swimPlayer->isFlying()) {
      $breakTimePerTick *= 5;
    }

    if ($this->swimPlayer->isUnderwater()) {
      $breakTimePerTick *= 5;
    }

    if ($breakTimePerTick > 0) {
      $progressPerTick = 1 / $breakTimePerTick;
      if ($this->swimPlayer->getEffects()->has(VanillaEffects::HASTE())) {
        $amplifier = $this->swimPlayer->getEffects()->get(VanillaEffects::HASTE())->getAmplifier() + 1;
        $progressPerTick *= (1 + 0.2 * $amplifier) * (1.2 ** $amplifier);
      }

      if ($this->swimPlayer->getEffects()->has(VanillaEffects::MINING_FATIGUE())) {
        $amplifier = $this->swimPlayer->getEffects()->get(VanillaEffects::MINING_FATIGUE())->getAmplifier() + 1;
        $progressPerTick *= 0.21 ** $amplifier;
      }
      return $progressPerTick;
    }

    return 1;
  }

  public function update(): bool
  {
    if ($this->swimPlayer->getPosition()->distanceSquared($this->blockPos->add(0.5, 0.5, 0.5)) > $this->maxPlayerDistance ** 2) {
      return false;
    }

    $newBreakSpeed = $this->calculateBreakProgressPerTick();
    if (abs($newBreakSpeed - $this->breakSpeed) > 0.0001) {
      $this->breakSpeed = $newBreakSpeed;
      $this->swimPlayer->getWorld()->broadcastPacketToViewers(
        $this->blockPos,
        LevelEventPacket::create(LevelEvent::BLOCK_BREAK_SPEED, (int)(65535 * $this->breakSpeed), $this->blockPos)
      );
    }

    $this->breakProgress += $this->breakSpeed;
    if (($this->fxTicker++ % $this->fxTickInterval) === 0 && $this->breakProgress < 1) {
      $this->swimPlayer->getWorld()->addParticle($this->blockPos, new BlockPunchParticle($this->block, $this->targetedFace));
      $this->swimPlayer->getWorld()->addSound($this->blockPos, new BlockPunchSound($this->block));
      $this->swimPlayer->broadcastAnimation(new ArmSwingAnimation($this->swimPlayer), $this->swimPlayer->getViewers());
    }

    if ($this->breakProgress >= 1 && $this->clientAttemptedBreakEarly) {
      $this->swimPlayer->breakBlock($this->blockPos);
    }

    return $this->breakProgress < 2;
  }

  public function setClientAttemptedTooEarly(): void
  {
    $this->clientAttemptedBreakEarly = true;
  }

  public function getBlockPos(): Vector3
  {
    return $this->blockPos;
  }

  public function getTargetedFace(): int
  {
    return $this->targetedFace;
  }

  public function setTargetedFace(int $face): void
  {
    Facing::validate($face);
    $this->targetedFace = $face;
  }

  public function getBreakSpeed(): float
  {
    return $this->breakSpeed;
  }

  public function getBreakProgress(): float
  {
    return $this->breakProgress;
  }

  public function getNextBreakProgress(int $ticks = 1): float
  {
    return $this->breakProgress + $this->calculateBreakProgressPerTick() * $ticks;
  }

  public function __destruct()
  {
    if ($this->swimPlayer->getWorld()->isInLoadedTerrain($this->blockPos)) {
      $this->swimPlayer->getWorld()->broadcastPacketToViewers(
        $this->blockPos,
        LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $this->blockPos)
      );
    }
  }

}