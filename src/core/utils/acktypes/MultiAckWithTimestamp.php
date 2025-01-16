<?php

namespace core\utils\acktypes;

use function microtime;

class MultiAckWithTimestamp {

  public int $timestamp;
  /** @param NslAck[] $acks */
  public function __construct(public array $acks, bool $noTimestamp = false) {
    if (!$noTimestamp) {
      $this->timestamp = (int) (microtime(true) * 1000);
    }
  }

}