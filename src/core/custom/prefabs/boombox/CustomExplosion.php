<?php

namespace core\custom\prefabs\boombox;

use core\scenes\duel\Duel;
use core\SwimCore;
use core\systems\player\SwimPlayer;
use pocketmine\block\Block;
use pocketmine\block\TNT;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\Explosion;
use pocketmine\world\particle\HugeExplodeSeedParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\ExplodeSound;

use function ceil;
use function floor;
use function min;
use function mt_rand;

class CustomExplosion extends Explosion
{

  public function __construct
  (
    public Position                    $source,
    public float                       $radius,
    private readonly Entity|Block|null $what = null
  )
  {
    parent::__construct($source, $radius, $what);
  }

  public function explodeB(): bool
  {
    // sphere radius of explosion
    $source = (new Vector3($this->source->x, $this->source->y, $this->source->z))->floor();
    $yield = min(100, (1 / $this->radius) * 100);

    // check if this explosion can even happen first
    if ($this->what instanceof Entity) {
      $ev = new EntityExplodeEvent($this->what, $this->source, $this->affectedBlocks, $yield);
      $ev->call();
      if ($ev->isCancelled()) {
        return false;
      } else {
        $yield = $ev->getYield();
        $this->affectedBlocks = $ev->getBlockList();
      }
    }

    // hit stuff nearby (players)
    $this->blowUpEntities();

    // check if the owner is a player since we will be using them to get the scene of where this explosion is taking place
    // something to note is if they switch scenes between the block being placed and then the explosion, that could mess stuff up royally
    $owner = $this->what->getOwningEntity();
    if (!($owner instanceof SwimPlayer)) {
      if (SwimCore::$DEBUG) echo("Boom box owner is not a player!\n");
      return false;
    }

    // must be in a duel scene to get the block manager
    $scene = $owner->getSceneHelper()?->getScene();
    if (!($scene instanceof Duel)) return false;

    $air = VanillaItems::AIR();
    $airBlock = VanillaBlocks::AIR();

    $bm = $scene->getBlockManager();
    foreach ($this->affectedBlocks as $block) {
      $pos = $block->getPosition();
      if ($block instanceof TNT) {
        $block->ignite(mt_rand(10, 30)); // not sure what consequences this could cause
      } else {

        // block manager tells us if we can break this block
        if ($bm->handleBlockBreakOnBlock($block)) {

          // it is a normal block to blow up
          if (mt_rand(0, 100) < $yield) {
            foreach ($block->getDrops($air) as $drop) {
              $this->world->dropItem($pos->add(0.5, 0.5, 0.5), $drop);
            }
          }
          // needed to create drops for inventories
          if (($t = $this->world->getTileAt($pos->x, $pos->y, $pos->z)) !== null) {
            $t->onBlockDestroyed();
          }

          // since we blew it up it is now air
          $this->world->setBlockAt($pos->x, $pos->y, $pos->z, $airBlock);
        }
      }
    }

    $this->world->addParticle($source, new HugeExplodeSeedParticle());
    $this->world->addSound($source, new ExplodeSound());

    return true;
  }

  private function blowUpEntities(): void
  {
    $explosionSize = $this->radius * 2;
    $minX = (int)floor($this->source->x - $explosionSize - 1);
    $maxX = (int)ceil($this->source->x + $explosionSize + 1);
    $minY = (int)floor($this->source->y - $explosionSize - 1);
    $maxY = (int)ceil($this->source->y + $explosionSize + 1);
    $minZ = (int)floor($this->source->z - $explosionSize - 1);
    $maxZ = (int)ceil($this->source->z + $explosionSize + 1);

    $explosionBB = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);

    // Entities to deal damage and knock back towards
    $list = $this->world->getNearbyEntities($explosionBB, $this->what instanceof Entity ? $this->what : null);
    foreach ($list as $entity) {
      $entityPos = $entity->getPosition();
      $distance = $entityPos->distance($this->source) / $explosionSize;

      if ($distance <= 1) {
        $motion = $entityPos->subtractVector($this->source);
        $motion->y = 0;

        $motion = $motion->normalize();

        $motion->y += 0.5;

        $impact = 1 - $distance;

        $damage = (int)((($impact * $impact + $impact) / 2) * 8 * $explosionSize + 1);
        $damage = (float)($damage / 3); // it was WAY too much damage
        if (SwimCore::$DEBUG) echo("Dealing explosion damage to " . $entity->getNameTag() . " | Damage: " . $damage . " | Distance: " . $distance . "\n");

        if ($this->what instanceof Entity) {
          $ev = new EntityDamageByEntityEvent($this->what, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage);
        } elseif ($this->what instanceof Block) {
          $ev = new EntityDamageByBlockEvent($this->what, $entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
        } else {
          $ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
        }

        $entity->attack($ev);
        $entity->setMotion($entity->getMotion()->addVector($motion->multiply($impact * 1.5)));
      }
    }
  }

}