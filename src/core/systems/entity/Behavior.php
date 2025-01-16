<?php

namespace core\systems\entity;

use core\systems\entity\entities\Actor;
use core\systems\scene\Scene;

abstract class Behavior
{

  protected Actor $parent;
  protected ?Scene $scene;

  public function __construct(Actor $actor, ?Scene $scene = null)
  {
    $this->parent = $actor;
    $this->scene = $scene;
  }

  abstract public function init(): void;

  abstract public function updateSecond(): void;

  abstract public function updateTick(): void;

  abstract public function exit(): void;

  /**
   * @return Actor
   */
  public function getParent(): Actor
  {
    return $this->parent;
  }

  /**
   * @param Actor $parent
   */
  public function setParent(Actor $parent): void
  {
    $this->parent = $parent;
  }

  /**
   * @return ?Scene
   */
  public function getScene(): ?Scene
  {
    return $this->scene ?? null;
  }

  /**
   * @param Scene $scene
   */
  public function setScene(Scene $scene): void
  {
    $this->scene = $scene;
  }

}