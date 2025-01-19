<?php

namespace core\systems\entity;

use core\SwimCore;
use pocketmine\entity\Entity;

class EntityBehaviorManager
{

  /**
   * @var Behavior[]
   * key is behavior class name string
   */
  private array $behaviorMap = array();

  protected Entity $parent;

  public function __construct(Entity $parent)
  {
    $this->parent = $parent;
  }

  /**
   * @return Entity
   */
  public function getParent(): Entity
  {
    return $this->parent;
  }

  public function init(): void
  {
    if (empty($this->behaviorMap)) return;
    foreach ($this->behaviorMap as $component) {
      $component->init();
      if (SwimCore::$DEBUG) echo("Component initing: " . $this->parent->getNameTag() . "\n");
    }
  }

  public function updateSecond(): void
  {
    if (empty($this->behaviorMap)) return;
    foreach ($this->behaviorMap as $component) {
      $component->updateSecond();
    }
  }

  public function updateTick(): void
  {
    if (empty($this->behaviorMap)) return;
    foreach ($this->behaviorMap as $component) {
      $component->updateTick();
    }
  }

  public function exit(): void
  {
    if (empty($this->behaviorMap)) return;
    foreach ($this->behaviorMap as $component) {
      $component->exit();
      if (SwimCore::$DEBUG) echo("Component exiting: " . $this->parent->getNameTag() . "\n");
    }
  }

  public function addBehavior(Behavior $behavior, string $name): void
  {
    $this->behaviorMap[$name] = $behavior;
  }

  public function hasBehavior(string $name): bool
  {
    return isset($this->behaviorMap[$name]);
  }

  public function getBehavior(string $name): ?Behavior
  {
    return $this->behaviorMap[$name] ?? null;
  }

}