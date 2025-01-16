<?php

namespace core\systems\player\components;

use core\systems\entity\entities\ClientEntity;
use core\systems\player\Component;
use core\utils\acktypes\AckType;
use core\utils\acktypes\EntityPositionAck;
use core\utils\acktypes\EntityRemovalAck;
use core\utils\acktypes\GamemodeChangeAck;
use core\utils\acktypes\KnockbackAck;
use core\utils\acktypes\MultiAckWithTimestamp;
use core\utils\acktypes\NoAiAck;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;

// A mini simulation of entities on each player's client for more precise reporting of positions and other related properties in physics.
class AckHandler extends Component
{

  const INTERPOLATION_TICKS = 3;
  const MAX_LENGTH = 1500;

  /** @var ClientEntity[] */
  private array $entities = [];

  private array $acks = [];

  public function recv(NetworkStackLatencyPacket $packet): bool
  {
    $ts = NetworkStackLatencyHandler::intRev($packet->timestamp);
    if (!isset($this->acks[$ts]))
      return false;
    $ack = $this->acks[$ts];

    if (isset($ack->timestamp)) {
      $this->swimPlayer->getNslHandler()->process($ack->timestamp);
    }

    foreach ($ack->acks as $nslAck) {
      switch ($nslAck::TYPE) {
        case AckType::ENTITY_POSITION:
          /** @var EntityPositionAck $nslAck */
          if (!isset($this->entities[$nslAck->actorRuntimeId])) {
            $this->entities[$nslAck->actorRuntimeId] = new ClientEntity();
          }
          $entity = $this->entities[$nslAck->actorRuntimeId];
          $entity->update($nslAck->pos, self::INTERPOLATION_TICKS);
          break;
        case AckType::KNOCKBACK:
          /** @var KnockbackAck $nslAck */
          $data = $this->swimPlayer->getAntiCheatData();
          if($data) {
            $data->runVelo = true;
            $data->currentMotion = $nslAck->motion;
            // $m = $data->getMotion();
            // $m?->setSleepTicks((int)(20 * $nslAck->motion->length())); // 1 second of ticks per length unit
            $data->lastMotionAckRecvTick = $this->swimPlayer->getServer()->getTick();
          }
          break;
        case AckType::ENTITY_REMOVAL:
          /** @var EntityRemovalAck $nslAck */
          $this->remove($nslAck->actorRuntimeId);
          break;
        case AckType::GAMEMODE_CHANGE:
          /** @var GamemodeChangeAck $nslAck */
          // $this->swimPlayer->getAntiCheatData()->clientGamemode = $nslAck->newGamemode;
          break;
        case AckType::NO_AI:
          /** @var NoAiAck $nslAck */
          // $this->swimPlayer->getAntiCheatData()->clientNoClientPredictions = $nslAck->noAi;
          break;
      }
    }

    unset($this->acks[$ts]);
    return true;
  }

  public function remove(int $id): void
  {
    unset($this->entities[$id]);
  }

  public function get(int $id): ?Vector3
  {
    if (!isset($this->entities[$id]))
      return null;
    return $this->entities[$id]->getPosition();
  }

  public function getPrev(int $id): ?Vector3
  {
    if (!isset($this->entities[$id]))
      return null;
    return $this->entities[$id]->getPrevPosition();
  }

  public function add(int $ts, MultiAckWithTimestamp $multiAck): void
  {
    $this->clean();
    $this->acks[NetworkStackLatencyHandler::intRev($ts)] = $multiAck;
  }

  private function clean(): void
  {
    if (count($this->acks) > self::MAX_LENGTH) {
      $this->swimPlayer->kick("Network error");
      $this->acks = [];
    }
  }

  public function updateTick(): void
  {
    foreach ($this->entities as $entity) {
      $entity->tick();
    }
  }

}