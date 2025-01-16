<?php

namespace core\custom\prefabs\rod;

use core\systems\entity\entities\DeltaSupportTrait;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\utils\Random;

class FishingHook extends Projectile
{

  use DeltaSupportTrait;

  public static function getNetworkTypeId(): string
  {
    return EntityIds::FISHING_HOOK;
  }

  protected function getInitialDragMultiplier(): float
  {
    return 0.01;
  }

  protected function getInitialGravity(): float
  {
    return 0.09;
  }

  protected function getInitialSizeInfo(): EntitySizeInfo
  {
    return new EntitySizeInfo(0.25, 0.25);
  }

  public function __construct(Location $location, ?Entity $shootingEntity, private readonly CustomFishingRod $item, ?CompoundTag $nbt = null)
  {
    $this->setCanSaveWithChunk(false);
    parent::__construct($location, $shootingEntity, $nbt);
  }

  public function onHitEntity(Entity $entityHit, RayTraceResult $hitResult): void
  {
    $damage = $this->getResultDamage();

    if ($this->getOwningEntity() !== null) {
      $event = new EntityDamageByChildEntityEvent($this->getOwningEntity(), $this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage);

      if (!$event->isCancelled()) {
        $entityHit->attack($event);
      }
    }

    $this->isCollided = true;
    $this->flagForDespawn();
  }

  protected function entityBaseTick(int $tickDiff = 1): bool
  {
    $hasUpdate = parent::entityBaseTick($tickDiff);
    $player = $this->getOwningEntity();
    $despawn = false;

    // Checks for automatic despawn
    if ($player instanceof Player) {
      if (
        $player->getInventory()->getItemInHand()->getTypeId() !== ItemTypeIds::FISHING_ROD || !$player->isAlive()
        || $player->isClosed() || $player->getLocation()->getWorld()->getId() !== $this->getLocation()->getWorld()->getId()
        || $player->getPosition()->distanceSquared($this->getPosition()) > 1600) {
        $despawn = true;
      }
    } else {
      $despawn = true;
    }

    if ($despawn) {
      $this->flagForDespawn();
      $hasUpdate = true;
    }

    return $hasUpdate;
  }

  public function flagForDespawn(): void
  {
    $owningEntity = $this->getOwningEntity();

    if ($owningEntity instanceof Player) {
      $owningEntity->fishing = false;
    }

    parent::flagForDespawn();
  }

  public function handleHookCasting(Vector3 $vec): void
  {
    $x = $vec->getX();
    $y = $vec->getY();
    $z = $vec->getZ();

    $f2 = 1.0;
    $f1 = 1.5;

    $rand = new Random();
    $f = sqrt($x * $x + $y * $y + $z * $z);
    $x = $x / $f;
    $y = $y / $f;
    $z = $z / $f;
    $x = $x + $rand->nextSignedFloat() * 0.007499999832361937;
    $y = $y + $rand->nextSignedFloat() * 0.007499999832361937 * $f2;
    $z = $z + $rand->nextSignedFloat() * 0.007499999832361937 * $f2;
    $x = $x * 1.5;
    $y = $y * $f1;
    $z = $z * $f1;

    $this->motion->x += $x;
    $this->motion->y += $y;
    $this->motion->z += $z;
  }

}