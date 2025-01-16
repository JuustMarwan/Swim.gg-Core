<?php

namespace core\utils\config;

class RegionInfo
{
  /** @config region-name */
  public string $regionName = "HUB";

  /** @conf auto-transfer */
  public string $autoTransfer = "";

  public function isHub(): bool {
    return strtoupper($this->regionName) === "HUB";
  }
}