<?php

namespace core\systems\player\components;

use core\SwimCore;
use core\systems\player\Component;
use core\systems\player\components\detections\Detection;
use core\utils\AABB;
use core\utils\AcData;
use core\utils\cordhook\CordHook;
use core\utils\security\LoginProcessor;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\block\NetherVines;
use pocketmine\entity\Location;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\player\GameMode;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\World;

class AntiCheatData extends Component
{
  /**
   * @var false to start
   */
  public bool $runVelo = false;

  /** @var Detection[] */
  private array $detections = [];

  // misc array of data, not really used anymore
  private array $acData = [];

  // unix time stamp
  private int $loginTime;

  public ?Vector3 $currentMotion = null;
  public ?Vector3 $currentLocation = null;
  public ?Vector3 $lastLocation = null;
  public ?Vector3 $lastClientPrediction = null;
  public ?Vector3 $currentMoveDelta = null;
  public ?Vector3 $lastMoveDelta = null;

  // you might really want to call
  public bool $hasBlockAbove = false;
  public bool $isCollidedHorizontally = false;

  private int $ticksSinceJumping = 0;
  private Location $lastOnGroundLocation;
  private int $ticksSinceGround = 0;

  // pitch and yaw
  public float $currentYawDelta = 0;
  public float $currentYaw = 0;
  public float $lastYawDelta = 0;

  public int $lastMotionAckRecvTick = 0;
  public int $ticksSinceMotion = 0;
  public int $ticksSinceInLiquid = 0;
  public int $ticksSinceInCobweb = 0;
  public int $ticksSinceClimbing = 0;
  private int $collisionBlockTimeoutXZ = 0;
  private int $collisionBlockTimeoutY = 0;
  private int $collisionBlockTimeoutGround = 0;

  // special collisions (ladder, vines, etc)
  private int $specialTimeout = 0;
  public bool $isSpecialColliding = false;
  public ?Block $lastSpecial = null;

  // caching
  private ?Block $lastCollidedWithHorizontally = null;

  // the block underneath us (call updateGroundCollision() to refresh this before getting it via getGroundBlock())
  private ?Block $groundBlock = null;

  public function init(): void
  {
    $this->loginTime = time();
    $this->doesUpdate = true;
    $this->lastClientPrediction = new Vector3(0, -0.0784, 0);
    $this->lastOnGroundLocation = $this->swimPlayer->getLocation();
    $this->lastLocation = $this->lastOnGroundLocation;
    $this->lastMoveDelta = Vector3::zero();
    $this->setDeviceOS();

    // register detections like this:
    // $this->killAura = $this->detections["KillAura"] = new KillAura($this->core, $this->swimPlayer, "KillAura");

    // init each detection
    foreach ($this->detections as $detection) {
      $detection->init();
    }
  }

  /**
   * @brief Done once on component init.
   *  Clients could maybe exploit this on login to device spoof on the fly if they can bypass the login processor check.
   */
  private function setDeviceOS(): void
  {
    $extraData = $this->swimPlayer->getPlayerInfo()->getExtraData();
    if ($extraData["DeviceModel"] == "" && $extraData["DeviceOS"] == 1) { // linux
      $deviceOS = 15;
    } else {
      $deviceOS = $extraData["DeviceOS"];
    }
    $this->setData(AcData::DEVICE_OS, LoginProcessor::$platformMap[$deviceOS]);
  }

  public function teleported(): void
  {
    foreach ($this->detections as $detection) {
      $detection->teleported();
    }
  }

  public function attacked(): void
  {
    foreach ($this->detections as $detection) {
      $detection->attacked();
    }
  }

  public function changedGameMode(): void
  {
    foreach ($this->detections as $detection) {
      $detection->changedGameMode();
    }
  }

  /**
   * @brief Updates the rewind data
   */
  private function updateRewindData(): void
  {
    $this->pushData(AcData::REWIND, $this->swimPlayer->getPosition());
    if (count($this->getData(AcData::REWIND)) > 20) {
      $this->spliceData(AcData::REWIND, 0, 1);
    }
  }

  /**
   * @brief Heart beat tick for all detection subcomponents
   */
  private function updateDetections(): void
  {
    foreach ($this->detections as $detection) {
      $detection->tick();
    }
  }

  public int $lastBlockPlaceTick = 0; // the server tick we last placed a block at
  private int $blocksBroken = 0; // this field resets to 0 after each tick update
  private int $blocksPlaced = 0; // this field resets to 0 after each tick update
  private int $totalBlockActions = 0;
  private float $totalBlockActionSpamFlags = 0;
  private float $scaffoldFlags = 0;
  private float $nukeFlags = 0;
  private const maxBlockFlags = 20;
  private const maxBlockActionsPerSecond = 20;

  // hard coded detection SwimPlayer block break and place function directly calls, checks if we did the same block action more than once this tick

  public function nukeCheck(): bool
  {
    if ($this->swimPlayer->getNslHandler()->getJitter() < 100) {
      return $this->blockDebounceCheck($this->blocksBroken, $this->nukeFlags, "Nuker");
    }
    return false;
  }

  // not used for now because false flag
  public function blockPlaceCheck(): bool
  {
    return $this->blockDebounceCheck($this->blocksPlaced, $this->scaffoldFlags, "Scaffold", 4); // it just false flags way too hard at 1 count max
  }

  // the only false flag concern to this is lag spikes
  private function blockDebounceCheck(int &$count, float &$flags, string $name, int $countMax = 1): bool
  {
    $gm = $this->swimPlayer->getGamemode();
    if ($gm == GameMode::CREATIVE || $gm == GameMode::SPECTATOR) return false;

    $this->totalBlockActions++;
    $count++;
    $flagged = $count > $countMax; // check if did the action more than count max this tick. For example nuker breaks more than 1 block in a tick.
    if ($flagged) {
      $flags++;
      $this->blockFlagAlert($name, $flags);
    }

    return $flagged;
  }

  private function blockFlagAlert(string $name, int|float $flags): void
  {
    if (((int)$flags) % 5 == 0) { // only alert every 5 flags
      $msg = TF::RED . "[AC] " . TF::GREEN . $this->swimPlayer->getName() . TF::WHITE . ": " . $name . " Flags: " . $flags
        . " (ping: " . TF::GREEN . $this->swimPlayer->getNslHandler()->getPing()
        . TF::WHITE . "ms, jitter: " . TF::GREEN . $this->swimPlayer->getNslHandler()->getJitter();
      Detection::StaffAlert($msg, $this->core);
    }
    if ($flags >= self::maxBlockFlags) {
      Detection::BanPlayer($this->swimPlayer, $this->core);
      Detection::PunishAlert($this->swimPlayer, $this->core, $name);
    }
  }

  // check how many block actions happened in the past second, if exceeded the limit then flag
  public function updateSecond(): void
  {
    if ($this->core->getServer()->getTicksPerSecondAverage() < 18) return;

    if ($this->totalBlockActions > self::maxBlockActionsPerSecond) {
      $this->blockFlagAlert("Block Spam", ++$this->totalBlockActionSpamFlags);
    }

    $this->totalBlockActions = 0;

    // also check player's hit box size (this class is becoming really messy and hacky)
    $this->hitBoxSelfSizeCheck();
  }

  private function hitBoxSelfSizeCheck(): void
  {
    $flagged = false;

    if ($this->swimPlayer->isSleeping() || $this->swimPlayer->isSwimming()) return;

    $hitBox = $this->swimPlayer->getSize();
    if ($this->swimPlayer->isSneaking()) {
      if ($hitBox->getEyeHeight() != 1.215 || $hitBox->getHeight() != 1.35 || $hitBox->getWidth() != 0.6) { // check if crouching hit box is correct
        $flagged = true;
      }
      //  } else if ($this->swimPlayer->isSwimming()) { // swimming is like broken or something
      // var_dump($this->swimPlayer->getSize());
    } else if ($hitBox->getEyeHeight() != 1.62 || $hitBox->getHeight() != 1.8 || $hitBox->getWidth() != 0.6) { // check if normal standing hit box is correct
      $flagged = true;
    }

    if ($flagged) {
      if ($this->core::$AC) {
        $kicked = TF::RED . "[KICK] " . TF::GREEN . "ANTICHEAT" . TF::WHITE . " Kicked " . TF::GREEN . $this->swimPlayer->getName() . TF::WHITE . ". Reason: " . TF::GREEN . " spoofed geo";
        $detail = " | spoofed geometry: " . $hitBox->getEyeHeight() . ", " . $hitBox->getHeight() . ", " . $hitBox->getWidth();
        CordHook::sendEmbed("Kicked " . $this->swimPlayer->getName() . $detail, "Microsoft AntiCheat Kick");
        Detection::StaffAlert($kicked, $this->core);
        $this->swimPlayer->kick("Error Code: Block Buddy");
      }
    }
  }

  public function updateTick(): void
  {
    if ($this->core->getServer()->getTicksPerSecondAverage() < 18) return; // not sure how bad this is to do here

    $this->updateRewindData();
    $this->updateDetections();

    $this->decayTickCountableDetectionFlags(0.01, $this->nukeFlags, $this->blocksBroken);
    $this->decayTickCountableDetectionFlags(0.001, $this->scaffoldFlags, $this->blocksPlaced);
    $this->decayTickCountableDetectionFlags(0.01, $this->totalBlockActionSpamFlags);

    $this->collisionBlockTimeoutXZ++;
    $this->collisionBlockTimeoutY++;
    $this->collisionBlockTimeoutGround++;
    $this->specialTimeout++;
  }

  /**
   * @param float $decay the amount to decay the flags class field by
   * @param float $flags the flags class field to decay
   * @param int|null $count if set and passed, this class field will be set to 0
   * @return void
   */
  private function decayTickCountableDetectionFlags(float $decay, float &$flags, ?int &$count = null): void
  {
    if (isset($count)) $count = 0;
    $flags -= $decay;
    if ($flags < 0) $flags = 0;
  }

  /**
   * @brief Call back event function that hits all detections handle virtual function.
   */
  public function handle(DataPacketReceiveEvent $event): void
  {
    if ($this->core->getServer()->getTicksPerSecondAverage() < 18) return;
    foreach ($this->detections as $detection) {
      $detection->handle($event);
    }
  }

  public function playerAuthInput(PlayerAuthInputPacket $pk): void
  {
    if ($this->core->getServer()->getTicksPerSecondAverage() < 18) return; // quit if server lagging

    $this->updateLocation($pk);
    $this->updateGroundAndJumping($pk);
    $this->updateYaws();
    $this->updateMotion($pk);
  }

  private function updateLocation(PlayerAuthInputPacket $pk): void
  {
    $this->lastClientPrediction = $pk->getDelta();
    $this->lastLocation = $this->currentLocation;
    $this->currentLocation = Location::fromObject($pk->getPosition()->subtract(0, 1.62, 0), $this->swimPlayer->getWorld(), $pk->getYaw(), $pk->getPitch());

    if (is_null($this->lastLocation)) {
      $this->lastLocation = $this->currentLocation;
      $this->currentMotion = null;
    }

    if ($this->currentMoveDelta !== null) {
      $this->lastMoveDelta->x = $this->currentMoveDelta->x;
      $this->lastMoveDelta->y = $this->currentMoveDelta->y;
      $this->lastMoveDelta->z = $this->currentMoveDelta->z;
    }
  }

  private function updateGroundAndJumping(PlayerAuthInputPacket $pk): void
  {
    if ($pk->getInputFlags()->get(PlayerAuthInputFlags::START_JUMPING)) {
      $this->ticksSinceJumping = 0;
    } else {
      $this->ticksSinceJumping++;
    }
    if ($this->swimPlayer->isOnGround()) {
      $this->lastOnGroundLocation = $this->swimPlayer->getLocation();
      $this->ticksSinceGround = 0;
    } else {
      $this->ticksSinceGround++;
    }
  }

  private function updateYaws(): void
  {
    $previousYaw = $this->currentYaw;
    $this->currentYaw = $this->currentLocation->yaw;
    $this->lastYawDelta = $this->currentYawDelta;
    $this->currentYawDelta = abs($this->currentYaw - $previousYaw);
    if ($this->currentYawDelta > 180) {
      $this->currentYawDelta = 360 - $this->currentYawDelta;
    }
  }

  private function updateMotion(PlayerAuthInputPacket $pk): void
  {
    $this->currentMoveDelta = $this->currentLocation->subtractVector($this->lastLocation);
    // if we have no move delta, we didn't move
    if ($this->currentMoveDelta->equals(Vector3::zero())) {
      $this->ticksSinceMotion++;
    } else {
      $this->ticksSinceMotion = 0;
    }
  }

  // We only do block collision checks every 5 ticks to save a little compute. This should be enough time to not cause false flags.
  // Can optionally set if the player needs to have moved.
  // This uses an integer reference for the timer value, which is intended to be class fields for the XZ and Y timers for different block collision checks.
  private function shouldEarlyExitFromCollision(int &$timer, bool $ignoreTimeout, bool $needsToHaveMoved = true): bool
  {
    if (!$ignoreTimeout) {
      if ($timer < 5) return true;
      if ($needsToHaveMoved && ($this->ticksSinceMotion > 0)) return true;
      $timer = 0;
    }

    return false;
  }

  // Updates hasBlockAbove
  public function updateAboveHeadCollision(bool $ignoreTimeout = false): void
  {
    if ($this->shouldEarlyExitFromCollision($this->collisionBlockTimeoutY, $ignoreTimeout, false)) return;

    // checks if a block is above the player's head
    // I wonder if caching the last block above the player's head is worth it (probably not due to how much the player moves)
    $this->hasBlockAbove = false; // maybe instead of a bool this should be a block reference?
    $vertAABB = AABB::fromPosition($this->swimPlayer->getPosition()->add(0, 1, 0), 0.5, 2)->toAABB();
    $vertBlocks = $this->swimPlayer->getWorld()->getCollisionBlocks($vertAABB);
    foreach ($vertBlocks as $block) {
      if ($block->getTypeId() != BlockTypeIds::AIR) {
        if (SwimCore::$DEBUG) echo($this->swimPlayer->getName() . " has a block above their head!\n");
        $this->hasBlockAbove = true;
        break;
      }
    }
  }

  public function getGroundBlock(bool $ignoreTimeout = false): ?Block
  {
    $this->updateGroundCollision($ignoreTimeout);
    return $this->groundBlock;
  }

  // Updates the block groundBlock
  public function updateGroundCollision(bool $ignoreTimeout = false): void
  {
    // Early exit if collision check should be skipped
    if ($this->shouldEarlyExitFromCollision($this->collisionBlockTimeoutGround, $ignoreTimeout, false)) {
      return;
    }

    // Define the player's current position
    $playerPosition = $this->swimPlayer->getPosition();

    // Define the AABB to cover the area directly below the player
    $vertAABB = AABB::fromPosition(
      $playerPosition->add(0, -1, 0), // Position one block below the player's feet
      0.5, // Half-width (covers from -0.5 to +0.5 on the X-axis)
      0.5 // Half-height (covers from -0.5 to +0.5 on the Y-axis)
    )->toAABB();

    // Retrieve all collision blocks within the defined AABB
    $vertBlocks = $this->swimPlayer->getWorld()->getCollisionBlocks($vertAABB);

    // Initialize variables to track the highest non-air block
    $highestY = -INF; // Start with the lowest possible value
    $highestBlock = null;

    // Iterate through each block to find the highest non-air block
    foreach ($vertBlocks as $block) {
      $this->groundBlock = $block; // this is always set

      // Get the block's Y-coordinate
      $blockY = $block->getPosition()->getY();

      // Check if the block is not air and is higher than the current highest
      if ($block->getTypeId() !== BlockTypeIds::AIR && $blockY > $highestY) {
        $highestY = $blockY;
        $highestBlock = $block;
      }
    }

    // After checking all blocks, set the ground block accordingly based on closest to player's feet (the highest up)
    if ($highestBlock !== null) {
      $this->groundBlock = $highestBlock;
      /*
      if (SwimCore::$DEBUG) {
        echo($this->swimPlayer->getName() . " is standing on " . $highestBlock->getName() . " at Y=" . $highestY . "\n");
      }
      */
    }
  }

  // Call this method when you are about to do spacial sensitive operations.
  // This will set the crucial variable isHorizontallyCollided, which can let detections know if the player is pushed up against blocks on the XZ plane.
  // This is primarily used to avoid false flags for the horizontal velocity check.
  // This has a dirty flag where it will not actually update the value if you already called this method within the last 5 ticks.
  // This is because the player most likely won't have moved much. We also do an early exit if they haven't moved since the last tick.
  // To bypass this I added a bool argument $ignoreTimeout which is false by default. You would need to do pass it as true for stuff like phase detections.
  public function updateBlockCollisions(bool $ignoreTimeout = false, bool $doBlockAbove = true, bool $doBlockBelow = true): void
  {
    if ($this->shouldEarlyExitFromCollision($this->collisionBlockTimeoutXZ, $ignoreTimeout)) {
      if ($doBlockAbove) $this->updateAboveHeadCollision($ignoreTimeout); // make sure to do block above check too if we are too early exit from XZ block plane checks
      if ($doBlockBelow) $this->updateGroundCollision($ignoreTimeout);
      return;
    }

    // now false, we will do stretched plane collision detections from the player against surrounding blocks
    $this->isCollidedHorizontally = false;

    $inLiquid = false;
    $inWeb = false;
    $climbing = false;

    // checks if a block is above the player's head
    if ($doBlockAbove) $this->updateAboveHeadCollision($ignoreTimeout);

    // now we need to check the blocks all around them
    $horizontalAABB = $this->swimPlayer->getBoundingBox()->expandedCopy(0.25, 0, 0.25);

    // use the cached block if we can to check if we are still colliding with it
    // this is to minimize calling getCollisionBlocks with the big box in the code below this if statement
    if ($this->lastCollidedWithHorizontally !== null) {
      $this->checkHorizontalCollision($this->lastCollidedWithHorizontally, $horizontalAABB);
      // if we are still colliding, we can return out of here
      if ($this->isCollidedHorizontally) {
        if (SwimCore::$DEBUG) echo($this->swimPlayer->getName() . " still colliding horizontally!\n");
        return; // might not want to do early exits later on once we have block-in detections for stuff like webs etc.
      }
    }

    // otherwise, this cached previous block is invalid, and we have to do a full swoop
    // Actually I don't think we want to invalidate this, I can image scenarios where you go off a wall then back onto it
    // $this->lastCollidedWithHorizontally = null;

    $bigBox = $this->swimPlayer->getBoundingBox()->expandedCopy(0.5, 0.5, 0.5);
    $collisionBlocks = $this->swimPlayer->getWorld()->getCollisionBlocks($bigBox);
    $printed = false; // debug so we only log once while looping through rest of blocks to report if we are colliding or not
    foreach ($collisionBlocks as $block) {
      // if (SwimCore::$DEBUG) echo($block->getName() . " | " . $block->getTypeId() . "\n");
      $id = $block->getTypeId();
      if (!$this->isCollidedHorizontally && $id != BlockTypeIds::AIR) {
        $this->checkHorizontalCollision($block, $horizontalAABB);
        if (SwimCore::$DEBUG && !$printed) {
          $printed = true;
          echo($this->swimPlayer->getName() . " is now colliding horizontally!\n");
        }
      }
      // We need to make sure that we are scanning in a proper area for detecting if we are in any of these blocks
      if ($id == BlockTypeIds::WATER || $id == BlockTypeIds::LAVA) $inLiquid = true;
      if ($id == BlockTypeIds::COBWEB) $inWeb = true;
      if ($id == BlockTypeIds::VINES || $id == BlockTypeIds::LADDER) $climbing = true;
    }

    // TODO: none of this below works properly because getCollisionBlocks() does not count the webs and liquid and vine/ladder blocks
    // This will be a problem in the future when we have games with these blocks in them (we already sort of do)

    if ($inLiquid) {
      $this->ticksSinceInLiquid = 0;
      // if (SwimCore::$DEBUG) echo("in liquid\n");
    } else {
      $this->ticksSinceInLiquid++;
    }

    if ($inWeb) {
      $this->ticksSinceInCobweb = 0;
      // if (SwimCore::$DEBUG) echo("in web\n");
    } else {
      $this->ticksSinceInCobweb++;
    }

    if ($climbing) {
      $this->ticksSinceClimbing = 0;
      // if (SwimCore::$DEBUG) echo("climbing\n");
    } else {
      $this->ticksSinceClimbing++;
    }

    if (SwimCore::$DEBUG && $this->isCollidedHorizontally) echo($this->swimPlayer->getName() . " collided horizontally\n");
  }

  // slabs and weird blocks like snow might be a problem
  // this sets isCollidedHorizontally to true if it is colliding!
  private function checkHorizontalCollision(Block $block, AxisAlignedBB $horizontalAABB): void
  {
    // Initialize the collision status to false
    $this->isCollidedHorizontally = false;
    // Check if the block ID is not AIR
    if ($block->getTypeId() !== BlockTypeIds::AIR) {
      // Get the number of collision boxes
      $collisionBoxCount = count($block->getCollisionBoxes());
      // If there are no collision boxes, use AABB to check intersection, this is for stuff like cobwebs, which doesn't work currently
      if ($collisionBoxCount == 0) {
        // $aabb = AABB::fromBlock($block);
        // TODO: implement raw intersects with, which assumes a default 1 cubic meter in volume AABB for the block
        // $this->isCollidedHorizontally = $aabb->intersectsWith($horizontalAABB);
      } else {
        // If there are collision boxes, use the block's collision method
        $this->isCollidedHorizontally = $block->collidesWithBB($horizontalAABB);
        // Cache the block we last collided with
        if ($this->isCollidedHorizontally) $this->lastCollidedWithHorizontally = $block;
      }
    }
  }

  private function getBlocksInBB(World $world, AxisAlignedBB $bb): array
  {
    $minX = (int)floor($bb->minX - 1);
    $minY = (int)floor($bb->minY - 1);
    $minZ = (int)floor($bb->minZ - 1);
    $maxX = (int)floor($bb->maxX + 1);
    $maxY = (int)floor($bb->maxY + 1);
    $maxZ = (int)floor($bb->maxZ + 1);

    $collides = [];

    for ($z = $minZ; $z <= $maxZ; ++$z) {
      for ($x = $minX; $x <= $maxX; ++$x) {
        for ($y = $minY; $y <= $maxY; ++$y) {
          $block = $world->getBlockAt($x, $y, $z);
          $collides[] = $block;
        }
      }
    }

    return $collides;
  }

  public function checkSpecial(): bool
  {
    if ($this->shouldEarlyExitFromCollision($this->specialTimeout, false)) {
      return $this->isSpecialColliding;
    }

    $bb = $this->swimPlayer->boundingBox->expandedCopy(0.2, 0.2, 0.2);

    foreach ($this->getBlocksInBB($this->swimPlayer->getWorld(), $bb) as $block) {
      if ($block->getTypeId() === BlockTypeIds::LADDER || $block->getTypeId() === BlockTypeIds::VINES
        || $block->getTypeId() === BlockTypeIds::CAVE_VINES || $block instanceof NetherVines || $block instanceof Liquid) {
        $this->isSpecialColliding = true;
        $this->lastSpecial = $block; // maybe use this for quick checking before calling getBlocksInBB? not sure if possible to do
        return true;
      }
    }

    $this->isSpecialColliding = false;
    return false;
  }

  public function getTime(): int
  {
    return $this->loginTime;
  }

  public function setData(int $type, mixed $val): void
  {
    $this->acData[$type] = $val;
  }

  public function unsetData(int $type): void
  {
    unset($this->acData[$type]);
  }

  public function getData(int $type)
  {
    return $this->acData[$type] ?? null;
  }

  public function pushData(int $type, mixed $val): void
  {
    $this->acData[$type][] = $val;
  }

  public function spliceData(int $type, int $offset, int|null $len = null): void
  {
    if (!isset($this->acData[$type])) return;
    array_splice($this->acData[$type], $offset, $len);
  }

  /**
   * @return Vector3|null
   */
  public function getLastLocation(): ?Vector3
  {
    return $this->lastLocation;
  }

  /**
   * @return Vector3|null
   */
  public function getCurrentLocation(): ?Vector3
  {
    return $this->currentLocation;
  }

  /**
   * @return Vector3|null
   */
  public function getCurrentMotion(): ?Vector3
  {
    return $this->currentMotion;
  }

  /**
   * @return int
   */
  public function getTicksSinceJumping(): int
  {
    return $this->ticksSinceJumping;
  }

  /**
   * @return Location
   */
  public function getLastOnGroundLocation(): Location
  {
    return $this->lastOnGroundLocation;
  }

  /**
   * @return Vector3|null
   */
  public function getCurrentMoveDelta(): ?Vector3
  {
    return $this->currentMoveDelta;
  }

  /**
   * @return Vector3|null
   */
  public function getLastClientPrediction(): ?Vector3
  {
    return $this->lastClientPrediction;
  }

  /**
   * @return int
   */
  public function getTicksSinceGround(): int
  {
    return $this->ticksSinceGround;
  }

  public function getDetections(): array
  {
    return $this->detections;
  }

}