<?php

namespace core\custom\prefabs\boombox;

use core\systems\entity\entities\DeltaSupportTrait;
use core\systems\player\SwimPlayer;
use pocketmine\entity\Attribute;
use pocketmine\entity\Location;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\utils\TextFormat;
use function array_map;

class SmoothPrimedTNT extends PrimedTNT
{

  use DeltaSupportTrait;

  public int $maxFuse;

  public static function getNetworkTypeId(): string
  {
    return EntityIds::TNT_MINECART;
  }

  private bool $breaksBlocks;
  private float $blastRadius;
  protected SwimPlayer $owner;

  public function __construct
  (
    SwimPlayer   $owner,
    Location     $location,
    bool         $breakBlocks = false,
    float        $blastRadius = 3.5,
    ?CompoundTag $nbt = null
  )
  {
    parent::__construct($location, $nbt);
    $this->owner = $owner;
    $this->setOwningEntity($owner);
    $this->blastRadius = $blastRadius;
    $this->breaksBlocks = $breakBlocks;
  }

  protected function sendSpawnPacket(Player $player): void
  {
    $player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
      $this->getId(),
      $this->getId(),
      static::getNetworkTypeId(),
      $this->getOffsetPosition($this->location),
      $this->getMotion(),
      $this->location->pitch,
      $this->location->yaw,
      $this->location->yaw,
      $this->location->yaw,
      array_map(function (Attribute $attr): NetworkAttribute {
        return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
      }, $this->attributeMap->getAll()),
      $this->getAllNetworkData(),
      new PropertySyncData([], []),
      []
    ));
  }

  public function explode(): void
  {
    $ev = new EntityPreExplodeEvent($this, 3.5);
    $ev->call();
    if (!$ev->isCancelled()) {

      //TODO: deal with underwater TNT (underwater TNT treats water as if it has a blast resistance of 0)
      $explosion = new CustomExplosion
      (
        Position::fromObject($this->location->add(0, $this->size->getHeight() / 2, 0), $this->getWorld()),
        $ev->getRadius(),
        $this
      );

      if ($ev->isBlockBreaking()) {
        $explosion->explodeA();
      }
      $explosion->explodeB();
    }
  }

  public function getOffsetPosition(Vector3 $vector3): Vector3
  {
    return $vector3->add(0, 1.25, 0);
  }

  protected function syncNetworkData(EntityMetadataCollection $properties): void
  {
    parent::syncNetworkData($properties);
    $properties->setFloat(EntityMetadataProperties::SCALE, 0.001);
  }

  public function setFuse(int $fuse): void
  {
    $this->maxFuse = $fuse;
    parent::setFuse($fuse);
  }

  // I am not sure if that would fix it (still learning)
  public function entityBaseTick(int $tickDiff = 1): bool
  {
    // Retrieve the remaining fuse time in ticks
    $currentFuse = $this->fuse; // Assuming this returns remaining ticks

    // Prevent division by zero
    if ($this->maxFuse <= 0) {
      $this->setNameTag(TextFormat::RED . "0.00");
      return parent::entityBaseTick($tickDiff);
    }

    // Convert remaining ticks to seconds with two decimal points
    $remainingSeconds = $currentFuse / 20;
    $formattedSeconds = number_format($remainingSeconds, 2);

    // Determine the color based on remaining fuse time
    if ($currentFuse > ($this->maxFuse / 2)) {
      $color = TextFormat::GREEN;
    } else if ($currentFuse > ($this->maxFuse / 3)) {
      $color = TextFormat::YELLOW;
    } else {
      $color = TextFormat::RED;
    }

    // Prevent negative time display just in case
    if ($remainingSeconds < 0) {
      $formattedSeconds = "0.00";
      $color = TextFormat::RED;
    }

    // Set the name tag with color and remaining seconds
    $this->setNameTag($color . $formattedSeconds . "s");
    $this->setNameTagVisible();
    //You Can Disable Always Visible If Error Happends
    $this->setNameTagAlwaysVisible();

    //Also You Need To Test This As I'm Not Sure If It Works.
    //And You Can revert it back if the custom explode function doesn't happen
    return parent::entityBaseTick($tickDiff);
  }

}
