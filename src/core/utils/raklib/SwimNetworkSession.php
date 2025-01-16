<?php

namespace core\utils\raklib;

use Closure;
use core\SwimCore;
use core\systems\player\components\NetworkStackLatencyHandler;
use core\systems\player\SwimPlayer;
use core\utils\acktypes\MultiAckWithTimestamp;
use core\utils\acktypes\NslAck;
use core\utils\ProtocolIdToVersion;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\timings\Timings;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class SwimNetworkSession extends NetworkSession
{

  // private static array $snowCompressorMap = [];
  private static ReflectionProperty $protocolRefl;

  private static string $supportedVersions;

  private ?Compressor $origCompressor = null;

  /** @var Closure[] */
  private array $afterPacketHandledCbs = [];

  private bool $nslEnabled = true;

  public function setNslEnabled(bool $enabled): void
  {
    $this->nslEnabled = $enabled;
  }

  /*
  public function setSnowy(bool $snowy) : void {
    if ($snowy) {
      if ($this->origCompressor !== null) {
        return;
      }
      $compressorId = spl_object_id($this->getCompressor());
      $this->origCompressor = $this->getCompressor();
      if (!isset(self::$snowCompressorMap[$compressorId])) {
        self::$snowCompressorMap[$compressorId] = clone $this->getCompressor();
      }
      $compressor = self::$snowCompressorMap[$compressorId];
    } else {
      if ($this->origCompressor === null) {
        return;
      }
      $compressor = $this->origCompressor;
      $this->origCompressor = null;
    }
    (new ReflectionClass(NetworkSession::class))->getProperty("compressor")->setValue($this, $compressor);
    $this->getPlayer()->resendChunks();
  }
  */

  public function startUsingChunk(int $chunkX, int $chunkZ, Closure $onCompletion): void
  {
    if ($this->origCompressor !== null) {
      /*
      $world = $this->getPlayer()->getLocation()->getWorld();
      if (SwimCore::$isNetherGames) {
        SnowyChunkCacheNG::getInstance($world, $this->getCompressor());
      } else {
        SnowyChunkCache::getInstance($world, $this->getCompressor());
      }
      */
    }
    parent::startUsingChunk($chunkX, $chunkZ, $onCompletion);
  }

  /** @var NslAck[] */
  private array $nslBuffer = [];

  public function addToNslBuffer(NslAck $ack): void
  {
    $this->nslBuffer[] = $ack;
  }

  public function addAfterPacketHandledCb(Closure $cb): void
  {
    $this->afterPacketHandledCbs[] = $cb;
  }

  public function handleEncoded(string $payload): void
  {
    parent::handleEncoded($payload);
    $this->callAfterPacketHandledCbs();
  }

  /* OLD
public function handleDataPacket(Packet $packet, string $buffer): void
{
  parent::handleDataPacket($packet, $buffer);

  if (count($this->afterPacketHandledCbs) > 0) {
    foreach ($this->afterPacketHandledCbs as $cb) {
      $cb();
    }
    $this->afterPacketHandledCbs = [];
  }
}
*/

  public function handleDataPacket(Packet $packet, string $buffer): void
  {
    if ($packet->pid() === PlayerAuthInputPacket::NETWORK_ID) {
      $this->callAfterPacketHandledCbs();
    }
    parent::handleDataPacket($packet, $buffer);
  }

  private function callAfterPacketHandledCbs(): void
  {
    if (count($this->afterPacketHandledCbs) > 0) {
      foreach ($this->afterPacketHandledCbs as $cb) {
        $cb();
      }
      $this->afterPacketHandledCbs = [];
    }
  }

  public function tick(): void
  {
    $this->flushNslBuffer();
    parent::tick();
  }

  public function queueCompressed(CompressBatchPromise|string $payload, bool $immediate = false): void
  {
    $this->flushNslBuffer(true);
    parent::queueCompressed($payload, $immediate);
  }

  private function flushNslBuffer(bool $mainPing = true): void
  {
    if (!$this->nslEnabled) {
      if (count($this->nslBuffer) > 0) {
        $this->nslBuffer = [];
      }
      return;
    }
    if (!$mainPing && count($this->nslBuffer) === 0) {
      return;
    }
    // PluginTimings::$nsl->startTiming();
    $pl = $this->getPlayer();
    if ($pl && $pl->isConnected()) {
      $timestamp = NetworkStackLatencyHandler::randomIntNoZeroEnd();
      $this->addToSendBuffer(self::encodePacketTimed(SwimCore::$isNetherGames ? PacketSerializer::encoder($this->getProtocolId()) : PacketSerializer::encoder(), NetworkStackLatencyPacket::create($timestamp * 1000, true)));
      /** @var SwimPlayer $pl */
      $pl->getAckHandler()?->add($timestamp, new MultiAckWithTimestamp($this->nslBuffer, !$mainPing));
      if (count($this->nslBuffer) > 0) {
        $this->nslBuffer = [];
      }
    }
    // PluginTimings::$nsl->stopTiming();
  }

  public function sendDataPacketRepeat(ClientboundPacket $packet, bool $immediate, int $times): bool
  {
    if (!$this->isConnected()) {
      return false;
    }

    $timings = Timings::getSendDataPacketTimings($packet);
    $timings->startTiming();
    try {
      if (DataPacketSendEvent::hasHandlers()) {
        $ev = new DataPacketSendEvent([$this], [$packet]);
        $ev->call();
        if ($ev->isCancelled()) {
          return false;
        }
        $packets = $ev->getPackets();
      } else {
        $packets = [$packet];
      }

      foreach ($packets as $evPacket) {
        $encoded = self::encodePacketTimed(SwimCore::$isNetherGames ? PacketSerializer::encoder($this->getProtocolId()) : PacketSerializer::encoder(), $evPacket);
        for ($i = 0; $i < $times; $i++) {
          $this->addToSendBuffer($encoded);
        }
      }
      if ($immediate) {
        //$this->flushSendBuffer(true);
      }

      return true;
    } finally {
      $timings->stopTiming();
    }
  }

  /**
   * @throws ReflectionException
   */
  public function disconnectIncompatibleProtocol(int $protocolVersion): void
  {
    if (SwimCore::$isNetherGames) {
      if (!isset(self::$protocolRefl)) {
        self::$protocolRefl = (new ReflectionClass(NetworkSession::class))->getProperty("protocolId");
      }
      self::$protocolRefl->setValue($this, $protocolVersion);
    }
    if (!isset(self::$supportedVersions)) {
      $supported = [];
      foreach (ProtocolIdToVersion::getMap() as $protocol => $name) {
        $supported[] = TextFormat::GREEN . $name . " ($protocol)";
      }
      sort($supported);
      self::$supportedVersions = "Supported version" . (count($supported) !== 1 ? "s" : "") . ": " . implode(TextFormat::WHITE . ", ", $supported);
    }
    $this->disconnect(TextFormat::RED . "We do not currently support your version of Minecraft ($protocolVersion).\n" . TextFormat::WHITE . self::$supportedVersions);
  }

}