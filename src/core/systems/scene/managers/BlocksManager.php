<?php

namespace core\systems\scene\managers;

use core\SwimCore;
use core\systems\scene\misc\BlockTicker;
use core\utils\PositionHelper;
use core\utils\TimeHelper;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\world\World;

class BlocksManager
{

  /**
   * key is Vector3
   * @var Block[]
   */
  private array $placedBlocks; // logs the blocks placed by player

  /**
   * @var int[]
   */
  private array $allowedToBreakFromMap; // blocks the player is allowed to break in the map

  /**
   * key is Vector3
   * @var Block[]
   */
  private array $brokenMapBlocks; // logs the blocks that were part of the map and got broken

  private bool $canPlaceBlocks;
  private bool $canBreakBlocks; // only applies to player placed blocks
  private bool $canBreakRegisteredBlocks; // if we can break blocks registered in our allow list, this override can break map blocks
  private bool $canBreakMapBlocks; // also applies to breaking pre-made map blocks
  private bool $prunes; // if replaces broken and placed blocks

  private World $world; // so server knows what world to place blocks back in during clean up

  private int $brokenLifeTime;
  private int $placedLifeTime;

  private const TIME = 0;
  private const BLOCK = 1;

  private SwimCore $core;
  private Server $server;

  /**
   * key is Vector3
   * @var BlockTicker[]
   */
  private array $chuncksToKeepAlive;

  public function __construct
  (
    SwimCore $core,
    World    $world,
    bool     $canPlaceBlocks = false,
    bool     $canBreakBlocks = false,
    bool     $canBreakMapBlocks = false,
    bool     $prune = false
  )
  {
    $this->core = $core;
    $this->server = $this->core->getServer();
    $this->world = $world;
    $this->placedBlocks = [];
    $this->brokenMapBlocks = [];
    $this->allowedToBreakFromMap = [];
    $this->chuncksToKeepAlive = [];
    $this->canPlaceBlocks = $canPlaceBlocks;
    $this->canBreakBlocks = $canBreakBlocks;
    $this->canBreakMapBlocks = $canBreakMapBlocks;
    $this->canBreakRegisteredBlocks = $canBreakMapBlocks;
    $this->prunes = $prune;

    // how long in ticks for a block to be replaced back to what it was, by default is 5 minutes
    $this->brokenLifeTime = TimeHelper::minutesToTicks(5);
    $this->placedLifeTime = $this->brokenLifeTime;
  }

  public function addToAllowedToBreakList(int $blockId): void
  {
    $this->allowedToBreakFromMap[$blockId] = true;
  }

  public function removeFromAllowedToBreakList(int $blockId): void
  {
    unset($this->allowedToBreakFromMap[$blockId]);
  }

  public function allowedToBreak(int $blockId): bool
  {
    return isset($this->allowedToBreakFromMap[$blockId]);
  }

  /**
   * @return bool
   */
  public function isPrunes(): bool
  {
    return $this->prunes;
  }

  /**
   * @param bool $prunes
   */
  public function setPrunes(bool $prunes = true): void
  {
    $this->prunes = $prunes;
  }

  /**
   * @return int
   */
  public function getBrokenLifeTime(): int
  {
    return $this->brokenLifeTime;
  }

  /**
   * @return int
   */
  public function getPlacedLifeTime(): int
  {
    return $this->placedLifeTime;
  }

  /**
   * @param int $brokenLifeTime
   */
  public function setBrokenLifeTime(int $brokenLifeTime): void
  {
    $this->brokenLifeTime = $brokenLifeTime;
  }

  /**
   * @param int $placedLifeTime
   */
  public function setPlacedLifeTime(int $placedLifeTime): void
  {
    $this->placedLifeTime = $placedLifeTime;
  }

  /**
   * Checks if a hashed Vector3 key exists in the given array.
   * @param Vector3 $vector The Vector3 object to generate the hash key.
   * @param array $array The array to check for the key.
   * @return bool True if the key exists, false otherwise.
   */
  private function isHashKeyInArray(Vector3 $vector, array $array): bool
  {
    return isset($array[PositionHelper::getVectorHashKey($vector)]);
  }

  /**
   * Adds a Vector3 object to an array using a hashed Vector3 key.
   * @param array $array The array to add to.
   * @param Vector3 $vector The Vector3 object to add.
   * @param mixed $item The item to add to the array.
   */
  private function addItemToArrayWithVector3Key(array &$array, Vector3 $vector, mixed $item): void
  {
    $array[PositionHelper::getVectorHashKey($vector)] = $item;
  }

  public function handleBlockPlace(BlockPlaceEvent $event): void
  {
    $time = $this->core->getServer()->getTick() + $this->placedLifeTime;
    if ($this->canPlaceBlocks) {
      foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
        $this->addItemToArrayWithVector3Key($this->placedBlocks, new Vector3($x, $y, $z), [self::BLOCK => $block, self::TIME => $time]);
      }
    } else {
      // how would you have blocks to place if the duel has block placements disabled?
      $event->cancel();
    }
  }

  public function handleBlockBreak(BlockBreakEvent $event): void
  {
    $allowed = $this->handleBlockBreakOnBlock($event->getBlock());
    if (!$allowed) $event->cancel();
  }

  // returns if the block was allowed to be broken
  public function handleBlockBreakOnBlock(Block $block): bool
  {
    // first check if we are allowed to break blocks, if not then cancel and return
    if (!$this->canBreakBlocks) {
      return false;
    }

    // get the block
    $position = $block->getPosition();
    $time = $this->server->getTick() + $this->brokenLifeTime;

    // get if the block we broke is a player placed block
    $inPlacedBlocks = $this->isInPlacedBlocks($position);

    // if we can break map blocks, or we can break registered blocks and the block is registered, then we can just log and return
    // checking via state id is kinda sus
    if ($this->canBreakMapBlocks || ($this->canBreakRegisteredBlocks && ($this->allowedToBreak($block->getTypeId()) || $this->allowedToBreak($block->getStateId())))) {
      if (!$inPlacedBlocks) { // if the block we broke was not in the player placed blocks array, we log it as needs to be replaced since it was part of the map
        $this->addItemToArrayWithVector3Key($this->brokenMapBlocks, $position, [self::BLOCK => $block, self::TIME => $time]);
        return true;
      }
    }

    // at this point if the broken block cords is in the placedBlocks array, that means it was allowed to be broken since it was placed by a player during the match
    return $inPlacedBlocks;
  }

  public function handleNaturalBlockEvent(BlockFormEvent|BlockSpreadEvent $event): void
  {
    $time = $this->server->getTick() + $this->placedLifeTime;
    $block = $event->getBlock();
    $this->addItemToArrayWithVector3Key($this->placedBlocks, $block->getPosition(), [self::BLOCK => $block, self::TIME => $time]);
  }

  public function handleBucketDump(PlayerBucketEmptyEvent $event): void
  {
    $time = $this->server->getTick() + $this->placedLifeTime;
    $blockFace = $event->getBlockFace();
    $blockClicked = $event->getBlockClicked();

    // Calculate the position offset by 1 in the correct axis determined by the block face
    $position = $blockClicked->getSide($blockFace)->getPosition();

    // Determine the type of block (water or lava) being placed
    // $block = $event->getItem()->getTypeId() == ItemTypeIds::WATER_BUCKET ? VanillaBlocks::WATER() : VanillaBlocks::LAVA();
    $block = $this->world->getBlock($position); // probably fine to just log what is already there

    // Add the block to the placedBlocks array with the calculated position and time
    $this->addItemToArrayWithVector3Key($this->placedBlocks, $position, [self::BLOCK => $block, self::TIME => $time]);
  }


  private function isInPlacedBlocks(Vector3 $vector3): bool
  {
    return $this->isHashKeyInArray($vector3, $this->placedBlocks);
  }

  // sets all placed blocks back to air
  public function clearPlacedBlocks(): void
  {
    foreach ($this->placedBlocks as $data) {
      $block = $data[self::BLOCK];
      $pos = $block->getPosition();
      // have to check if terrain is loaded, this might not be nice on performance
      if ($this->world->isInLoadedTerrain($pos)) {
        $this->world->setBlock($pos, VanillaBlocks::AIR());
      }
    }
    $this->placedBlocks = [];
  }

  // sets all blocks broken from the map back to what they were
  public function replaceBrokenMapBlocks(): void
  {
    foreach ($this->brokenMapBlocks as $data) {
      $block = $data[self::BLOCK];
      $pos = $block->getPosition();
      // have to check if terrain is loaded, this might not be nice on performance
      if ($this->world->isInLoadedTerrain($pos)) {
        $this->world->setBlock($pos, $block);
      }
    }
    $this->brokenMapBlocks = [];
  }

  public function cleanMap(): void
  {
    if (SwimCore::$DEBUG) echo("Cleaning map\n"); // actually we don't need a pointer in this class to the parent scene, so we can't log which map is cleaned
    $this->clearPlacedBlocks();
    $this->replaceBrokenMapBlocks();
    $this->clearChunkLoaders();
  }

  // Getter for canPlaceBlocks
  public function getCanPlaceBlocks(): bool
  {
    return $this->canPlaceBlocks;
  }

  // Setter for canPlaceBlocks
  public function setCanPlaceBlocks(bool $canPlaceBlocks): void
  {
    $this->canPlaceBlocks = $canPlaceBlocks;
  }

  // Getter for canBreakBlocks
  public function getCanBreakBlocks(): bool
  {
    return $this->canBreakBlocks;
  }

  // Setter for canBreakBlocks
  public function setCanBreakBlocks(bool $canBreakBlocks): void
  {
    $this->canBreakBlocks = $canBreakBlocks;
  }

  // Getter for canBreakMapBlocks
  public function getCanBreakMapBlocks(): bool
  {
    return $this->canBreakMapBlocks;
  }

  // Setter for canBreakMapBlocks
  public function setCanBreakMapBlocks(bool $canBreakMapBlocks): void
  {
    $this->canBreakMapBlocks = $canBreakMapBlocks;
  }

  /**
   * @return bool
   */
  public function isCanBreakRegisteredBlocks(): bool
  {
    return $this->canBreakRegisteredBlocks;
  }

  /**
   * @param bool $canBreakRegisteredBlocks
   */
  public function setCanBreakRegisteredBlocks(bool $canBreakRegisteredBlocks): void
  {
    $this->canBreakRegisteredBlocks = $canBreakRegisteredBlocks;
  }

  // place an array of vector3 positions of a single block type
  public function placeBlocks(array $positions, Block $block, bool $log = true): void
  {
    $time = $this->server->getTick();
    foreach ($positions as $pos) {
      // if ($this->world->isInLoadedTerrain($pos))
      if ($this->world->isInWorld($pos->x, $pos->y, $pos->z)) {
        $this->world->setBlock($pos, $block);

        if ($log) {
          $this->addItemToArrayWithVector3Key($this->placedBlocks, $pos, [self::BLOCK => $block, self::TIME => $time]);
        }
      }
    }
  }

  // prunes all old blocks (this can maybe get expensive)
  public function updateSecond(): void
  {
    if (!$this->prunes) return;

    $time = $this->server->getTick();

    // set back the placed blocks to air
    foreach ($this->placedBlocks as $key => $data) {
      if ($time >= $data[self::TIME]) {
        /** @var Block $block */
        $block = $data[self::BLOCK];
        $pos = $block->getPosition();
        // if ($this->world->isInLoadedTerrain($pos))
        $this->world->setBlock($pos, VanillaBlocks::AIR());

        // if (SwimCore::$DEBUG) echo "Removing placed block: " . $block->getName() . "\n";
        unset($this->placedBlocks[$key]);
      }
    }

    // set back the broken map blocks to what they were
    foreach ($this->brokenMapBlocks as $key => $data) {
      if ($time >= $data[self::TIME]) {
        /** @var Block $block */
        $block = $data[self::BLOCK];
        $pos = $block->getPosition();
        // if ($this->world->isInLoadedTerrain($pos))
        $this->world->setBlock($pos, $block);

        // if (SwimCore::$DEBUG) echo "Replacing map block: " . $block->getName() . "\n";
        unset($this->brokenMapBlocks[$key]);
      }
    }
  }

  // add a chunk loader to the world, if a loader already exists at this position, it is removed and freed, and then replaced
  public function addChunkLoader(Vector3 $position, bool $tick = false): void
  {
    $key = $this->removeChunkLoader($position);
    $ticker = new BlockTicker($this->world, $position, $tick);
    $ticker->enableChunkTicker($tick);
    $this->chuncksToKeepAlive[$key] = $ticker;
  }

  // returns they key of the hash key of the position passed in
  // this also frees the chunk loader from the world
  public function removeChunkLoader(Vector3 $position): int
  {
    $key = PositionHelper::getVectorHashKey($position);
    if (self::isHashKeyInArray($position, $this->chuncksToKeepAlive)) {
      $this->chuncksToKeepAlive[$key]->free();
      unset($this->chuncksToKeepAlive[$key]);
    }
    return $key;
  }

  // frees all chunk loaders and empties the array
  public function clearChunkLoaders(): void
  {
    foreach ($this->chuncksToKeepAlive as $loader) {
      $loader->free();
    }
    $this->chuncksToKeepAlive = [];
  }

}