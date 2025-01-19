<?php

namespace core\systems\entity\entities;

use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\timings\Timings;

class EasierPickUpItemEntity extends ItemEntity
{

  use DeltaSupportTrait;

  protected function entityBaseTick(int $tickDiff = 1): bool
  {
    if ($this->closed) {
      return false;
    }

    Timings::$itemEntityBaseTick->startTiming();
    try {

      $hasUpdate = parent::entityBaseTick($tickDiff);

      if ($this->isFlaggedForDespawn()) {
        return $hasUpdate;
      }

      if ($this->pickupDelay !== self::NEVER_DESPAWN && $this->pickupDelay > 0) { //Infinite delay
        $hasUpdate = true;
        $this->pickupDelay -= $tickDiff;
        if ($this->pickupDelay < 0) {
          $this->pickupDelay = 0;
        }
      }

      if (
        $this->hasMovementUpdate()
        && ($this->pickupDelay !== self::NEVER_DESPAWN && $this->item->getCount() < $this->item->getMaxStackSize())
        && $this->despawnDelay % self::MERGE_CHECK_PERIOD === 0
      ) {

        $mergeable = [$this]; // in case the merge target ends up not being this
        $mergeTarget = $this;
        foreach ($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(0.5, 1.0, 0.5), $this) as $entity) {
          if (!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn()) {
            continue;
          }

          if ($entity->isMergeable($this)) {
            $mergeable[] = $entity;
            if ($entity->item->getCount() > $mergeTarget->item->getCount()) {
              $mergeTarget = $entity;
            }
          }
        }
        foreach ($mergeable as $itemEntity) {
          if ($itemEntity !== $mergeTarget) {
            $itemEntity->tryMergeInto($mergeTarget);
          }
        }
      }

      if (!$this->isFlaggedForDespawn() && $this->despawnDelay !== self::NEVER_DESPAWN) {
        $hasUpdate = true;
        $this->despawnDelay -= $tickDiff;
        if ($this->despawnDelay <= 0) {
          $ev = new ItemDespawnEvent($this);
          $ev->call();
          if ($ev->isCancelled()) {
            $this->despawnDelay = self::DEFAULT_DESPAWN_DELAY;
          } else {
            $this->flagForDespawn();
          }
        }
      }

      return $hasUpdate;
    } finally {
      Timings::$itemEntityBaseTick->stopTiming();
    }
  }

}
