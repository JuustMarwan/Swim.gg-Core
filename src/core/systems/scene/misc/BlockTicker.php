<?php

namespace core\systems\scene\misc;

use core\SwimCore;
use pocketmine\math\Vector3;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkTicker;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

// the point of this class is to keep chunks in memory in a world that doesn't auto save so things don't go out of memory
class BlockTicker
{

  private World $world;
  private Vector3 $position;
  private ?ChunkTicker $chunkTicker;
  private ?ChunkLoader $chunkLoader;
  private int $x;
  private int $z;
  private bool $chunkTickerEnabled;

  public function __construct(World $world, Vector3 $position, bool $ticking = false)
  {
    $this->world = $world;

    $this->chunkLoader = new class implements ChunkLoader {
    };

    $this->chunkTicker = new ChunkTicker();
    $this->chunkTickerEnabled = $ticking;

    $this->setPosition($position, false);
  }

  /**
   * Set a new position for the ticker, unregistering the old one and registering at the new position.
   * @param Vector3 $newPosition
   * @param bool $free
   */
  public function setPosition(Vector3 $newPosition, bool $free = true): void
  {
    // Free the current position if instructed
    if ($free) $this->free();

    // Update position
    $this->position = $newPosition;
    $this->x = $this->position->getFloorX() >> Chunk::COORD_BIT_SIZE;
    $this->z = $this->position->getFloorZ() >> Chunk::COORD_BIT_SIZE;

    // Register the new position
    $this->registerChunkLoaderAndTicker();
  }

  /**
   * Registers the chunk loader and ticker for the current position.
   */
  private function registerChunkLoaderAndTicker(): void
  {
    if (SwimCore::$DEBUG) echo("Registering Chunk Loading: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
    $this->world->registerChunkLoader($this->chunkLoader, $this->x, $this->z);

    if ($this->chunkTickerEnabled) {
      if (SwimCore::$DEBUG) echo("Registering Chunk Ticker: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
      $this->world->registerTickingChunk($this->chunkTicker, $this->x, $this->z);
    }
  }

  /**
   * Enable or disable the chunk ticker.
   * If set to false, unregister the chunk ticker if it's active.
   * @param bool $enable
   */
  public function enableChunkTicker(bool $enable): void
  {
    if ($this->chunkTicker !== null) {
      if ($enable && !$this->chunkTickerEnabled) {
        // Register chunk ticker if it was previously disabled
        if (SwimCore::$DEBUG) echo("Enabling and Registering Chunk Ticker: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
        $this->world->registerTickingChunk($this->chunkTicker, $this->x, $this->z);
      } elseif (!$enable && $this->chunkTickerEnabled) {
        // Unregister chunk ticker if it is enabled
        if (SwimCore::$DEBUG) echo("Disabling and Unregistering Chunk Ticker: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
        $this->world->unregisterTickingChunk($this->chunkTicker, $this->x, $this->z);
      }
    }

    $this->chunkTickerEnabled = $enable;
  }

  /**
   * Free the current chunk loader and ticker (if enabled).
   */
  public function free(): void
  {
    if ($this->chunkLoader !== null) {
      if (SwimCore::$DEBUG) echo("Removing Chunk Loader: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
      $this->world->unregisterChunkLoader($this->chunkLoader, $this->x, $this->z);
    }

    if ($this->chunkTickerEnabled) {
      if ($this->chunkTicker !== null) {
        if (SwimCore::$DEBUG) echo("Removing Chunk Ticker: " . $this->x . " " . $this->z . " | " . $this->world->getFolderName() . "\n");
        $this->world->unregisterTickingChunk($this->chunkTicker, $this->x, $this->z);
      }
    }
  }

  /**
   * @return Vector3
   */
  public function getPosition(): Vector3
  {
    return $this->position;
  }

  /**
   * @return World
   */
  public function getWorld(): World
  {
    return $this->world;
  }

  /**
   * @return bool
   */
  public function isChunkTickerEnabled(): bool
  {
    return $this->chunkTickerEnabled;
  }

}
