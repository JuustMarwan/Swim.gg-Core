<?php

namespace core\communicator;

use core\communicator\packet\DisconnectPacket;
use core\communicator\packet\DiscordEmbedSendPacket;
use core\communicator\packet\DiscordUserRequestPacket;
use core\communicator\packet\DiscordUserResponsePacket;
use core\communicator\packet\PacketDecoder;
use core\communicator\packet\PacketPool;
use core\communicator\packet\PlayerListRequestPacket;
use core\communicator\packet\types\CrashInfo;
use core\communicator\packet\types\DisconnectReason;
use core\communicator\packet\types\embed\Embed;
use core\communicator\packet\types\Region;
use core\SwimCore;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\scheduler\ClosureTask;
use pocketmine\thread\ThreadCrashInfoFrame;
use Ramsey\Uuid\Uuid;

class Communicator
{

  private PthreadsChannelReader $pthreadReader;
  private PthreadsChannelWriter $pthreadWriter;
  private CommunicatorThread $communicatorThread;
  private PacketDecoder $decoder;

  private PacketPool $pool;

  private DiscordInfo $discordInfo;

  /** @var Region[] */
  private array $otherRegions = [];
  private array $regionPlayers = [];
  private array $discordUserRequests = [];

  public function __construct(private readonly SwimCore $core)
  {
    $this->discordInfo = new DiscordInfo();

    $this->pool = new PacketPool();

    /** @phpstan-var ThreadSafeArray<int, string> $mainToThreadBuffer */
    $mainToThreadBuffer = new ThreadSafeArray();
    /** @phpstan-var ThreadSafeArray<int, string> $threadToMainBuffer */
    $threadToMainBuffer = new ThreadSafeArray();

    $this->pthreadReader = new PthreadsChannelReader($threadToMainBuffer);
    $this->pthreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);

    $this->decoder = new PacketDecoder;

    $sleeperEntry = $core->getServer()->getTickSleeper()->addNotifier(function (): void {
      $buf = $this->pthreadReader->read();
      try {
        $this->decoder->decodeFromString($buf, $this);
      } catch (PacketDecodeException $e) {
        $this->core->getLogger()->error($e->getMessage());
      }
    });

    $this->communicatorThread = new CommunicatorThread
    (
      $mainToThreadBuffer,
      $threadToMainBuffer,
      $sleeperEntry,
      $core->getServer()->getLogger(),
      $core->getRegionInfo()->regionName,
      $core->getSwimConfig()->communicator->ip,
      $core->getSwimConfig()->communicator->port
    );

    $this->communicatorThread->start();

    $core->getScheduler()->scheduleRepeatingTask(new ClosureTask(function () {
      foreach ($this->otherRegions as $region => $ip) {
        $pk = new PlayerListRequestPacket;
        $pk->regionName = $region;
        $this->write($pk->encodeToString());
      }
    }), 40);
  }

  public function write(string $payload): void
  {
    $this->pthreadWriter->write($payload);
  }

  public function getPacketPool(): PacketPool
  {
    return $this->pool;
  }

  public function getCore(): SwimCore
  {
    return $this->core;
  }

  public function getDiscordInfo(): DiscordInfo
  {
    return $this->discordInfo;
  }

  public function close(DisconnectReason $reason): void
  {
    $pk = new DisconnectPacket;
    $pk->reason = $reason;
    if ($reason === DisconnectReason::SERVER_CRASH) {
      global $lastError;
      if (isset($lastError["trace"])) {
        $trace = [];
        foreach ($lastError["trace"] as $bt) {
          /** @var ThreadCrashInfoFrame $bt */
          $trace[] = $bt->getPrintableFrame();
        }
        $pk->crashInfo = CrashInfo::create($lastError["type"], $lastError["message"], $lastError["file"], $lastError["line"], $trace);
      }
    }
    $this->write($pk->encodeToString());
    $this->communicatorThread->close();
  }

  public function onDiscordUserResponse(DiscordUserResponsePacket $pk): void
  {
    if (isset($this->discordUserRequests[$pk->requestId])) {
      $this->discordUserRequests[$pk->requestId]($pk);
      unset($this->discordUserRequests[$pk->requestId]);
    }
  }

  public function requestDiscordUser(string $userId, \Closure $cb): void
  {
    $id = Uuid::uuid4()->toString();
    $this->discordUserRequests[$id] = $cb;
    $pk = new DiscordUserRequestPacket;
    $pk->requestId = $id;
    $pk->userId = $userId;
    $this->write($pk->encodeToString());
  }

  public function sendEmbed(string $channelId, Embed $embed): void
  {
    $pk = new DiscordEmbedSendPacket;
    $pk->channelId = $channelId;
    $pk->embed = $embed;
    $this->write($pk->encodeToString());
  }

  public function updateRegionPlayers(string $region, ?array $players): void
  {
    if ($players === null) {
      unset($this->regionPlayers[$region]);
      return;
    }
    $this->regionPlayers[$region] = $players;
  }

  public function getRegionPlayers(string $region): ?array
  {
    return $this->regionPlayers[$region] ?? null;
  }

  public function getAllRegionPlayers(): array
  {
    return $this->regionPlayers;
  }

  public function setOtherRegions(array $otherRegions): void
  {
    $this->otherRegions = $otherRegions;
  }

  /** @return Region[] */
  public function getOtherRegions(): array
  {
    return $this->otherRegions;
  }

}