<?php

namespace core\systems\scene\managers;

use core\SwimCore;
use pocketmine\entity\object\ItemEntity;

// this is solely to keep track of item entities in the scene
class DroppedItemManager
{

  /**
   * @var ItemEntity[]
   */
  public array $droppedItems = array();

  public function addDroppedItem(ItemEntity $entity, int $delayTicks = ItemEntity::NEVER_DESPAWN): void
  {
    if (SwimCore::$DEBUG) echo("Adding item {$entity->getId()} to dropped items\n");
    $entity->setDespawnDelay($delayTicks);
    $this->droppedItems[$entity->getId()] = $entity;
  }

  public function removeDroppedItem(ItemEntity $entity): void
  {
    if (SwimCore::$DEBUG) echo("Removing item {$entity->getId()} from dropped items\n");
    unset($this->droppedItems[$entity->getId()]);
  }

  public function despawnAll(): void
  {
    foreach ($this->droppedItems as $item) {
      if ($item && $item->isAlive()) $item->kill();
    }
    // clear
    $this->droppedItems = array();
  }

}