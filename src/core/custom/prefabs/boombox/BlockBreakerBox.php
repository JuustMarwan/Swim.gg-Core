<?php

namespace core\custom\prefabs\boombox;

use core\systems\player\SwimPlayer;
use core\utils\InventoryUtil;
use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\IgniteSound;

class BlockBreakerBox extends BaseBox
{

  public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null): bool
  {
    if ($player instanceof SwimPlayer) {

      // if on cool down then don't do throwing tnt logic
      $cd = $player->getCoolDowns();
      if ($cd?->onCoolDown($item)) {
        return false;
      }

      $pos = $blockReplace->getPosition();
      $primedTnt = new SmoothPrimedTNT($player, Location::fromObject($pos->add(0.5, 0, 0.5), $pos->getWorld()), true);
      $primedTnt->setFuse(40);
      $primedTnt->spawnToAll();
      $primedTnt->broadcastSound(new IgniteSound());
      $mot = (new Random())->nextSignedFloat() * M_PI * 2;
      $primedTnt->setMotion(new Vector3(-sin($mot) * 0.02, 0.2, -cos($mot) * 0.02));

      // then add a cool down to it
      $cd->setCoolDown($item, 0.10, false);
      InventoryUtil::forceItemPop($player, $item);
    }

    return true;
  }

}