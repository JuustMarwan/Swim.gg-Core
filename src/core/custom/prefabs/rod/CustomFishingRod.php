<?php

namespace core\custom\prefabs\rod;

use pocketmine\entity\Location;
use pocketmine\item\FishingRod;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\ItemUseResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\ThrowSound;

class CustomFishingRod extends FishingRod
{

  protected ?FishingHook $entity = null;

  private bool $inUse = false;

  public function __construct(ItemIdentifier $identifier = new ItemIdentifier(ItemTypeIds::FISHING_ROD), string $name = "Rod", array $enchantmentTags = [])
  {
    parent::__construct($identifier, $name, $enchantmentTags);
    $this->setCustomName(TextFormat::RESET . TextFormat::GRAY . "Rod");
  }

  public function setInUse(bool $inUse) : void {
    $this->inUse = $inUse;
  }

  public function getMaxDurability(): int
  {
    return 150;
  }

  protected function serializeCompoundTag(CompoundTag $tag): void
  {
    parent::serializeCompoundTag($tag);
    $this->damage !== 0 ? $tag->setInt("Damage", (int)($this->damage * (parent::getMaxDurability() / $this->getMaxDurability()))) : $tag->removeTag("Damage");
  }

  public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems): ItemUseResult
  {
    if (!$this->entity || $this->entity->isClosed()) {
      $player->fishing = true;
      $this->entity = new FishingHook(Location::fromObject($player->getEyePos()->subtract(0, 0.2, 0), $player->getWorld()), $player, $this);
      $this->entity->handleHookCasting($directionVector->multiply(2.2));
      $this->entity->spawnToAll();
      $player->getWorld()->addSound($player->getPosition(), new ThrowSound());
      $this->applyDamage(1);
    } else {
      $this->entity->flagForDespawn();
      $player->fishing = false;
      unset($this->entity);
    }

    return ItemUseResult::SUCCESS;
  }

}