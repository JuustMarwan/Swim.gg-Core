<?php

namespace core\utils\raklib;

use raklib\server\ProtocolAcceptor;
use function in_array;

class MultiProtocolAcceptor implements ProtocolAcceptor
{

  /**
   * @param int[] $protocolVersions
   */
  public function __construct(private readonly int $primaryVersion, private readonly array $protocolVersions)
  {
  }

  public function accepts(int $protocolVersion): bool
  {
    return in_array($protocolVersion, $this->protocolVersions, true);
  }

  public function getPrimaryVersion(): int
  {
    return $this->primaryVersion;
  }

}
