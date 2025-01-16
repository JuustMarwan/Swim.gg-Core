<?php

namespace core\utils\raklib;

use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;
use pocketmine\player\GameMode;
use pocketmine\Server;
use pocketmine\timings\Timings;
use raklib\server\ipc\RakLibToUserThreadMessageReceiver;
use raklib\server\ipc\UserToRakLibThreadMessageSender;
use raklib\utils\InternetAddress;
use pocketmine\YmlServerProperties;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class SwimRakLibInterface extends RakLibInterface
{

  private Server $server;
  private int $rakServerId;
  private RakLibToUserThreadMessageReceiver $eventReceiver;
  private UserToRakLibThreadMessageSender $rawInterface;
  private ReflectionProperty $sessionsRefl;
  private ReflectionProperty $networkRefl;

  public function __construct(
    Server                         $server,
    string                         $ip,
    int                            $port,
    bool                           $ipV6,
    private PacketBroadcaster      $packetBroadcaster,
    private EntityEventBroadcaster $entityEventBroadcaster,
    private TypeConverter          $typeConverter,
    private array                  $motds
  )
  {
    $this->server = $server;

    $refl = new ReflectionClass(RakLibInterface::class);

    $this->sessionsRefl = $refl->getProperty("sessions");
    $this->networkRefl = $refl->getProperty("network");

    $refl->getProperty("server")->setValue($this, $server);

    $refl->getProperty("packetBroadcaster")->setValue($this, $this->packetBroadcaster);
    $refl->getProperty("entityEventBroadcaster")->setValue($this, $this->entityEventBroadcaster);
    $refl->getProperty("typeConverter")->setValue($this, $this->typeConverter);

    $this->rakServerId = mt_rand(0, PHP_INT_MAX);
    $refl->getProperty("rakServerId")->setValue($this, $this->rakServerId);

    $sleeperEntry = $this->server->getTickSleeper()->addNotifier(function (): void {
      Timings::$connection->startTiming();
      try {
        while ($this->eventReceiver->handle($this))
          ;
      } finally {
        Timings::$connection->stopTiming();
      }
    });
    $refl->getProperty("sleeperNotifierId")->setValue($this, $sleeperEntry->getNotifierId());

    /** @phpstan-var ThreadSafeArray<int, string> $mainToThreadBuffer */
    $mainToThreadBuffer = new ThreadSafeArray();
    /** @phpstan-var ThreadSafeArray<int, string> $threadToMainBuffer */
    $threadToMainBuffer = new ThreadSafeArray();

    $refl->getProperty("rakLib")->setValue($this, new SwimRakLibServer(
      $this->server->getLogger(),
      $mainToThreadBuffer,
      $threadToMainBuffer,
      new InternetAddress($ip, $port, $ipV6 ? 6 : 4),
      $this->rakServerId,
      $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::NETWORK_MAX_MTU_SIZE, 1492),
      11,
      $sleeperEntry
    ));

    $this->eventReceiver = new RakLibToUserThreadMessageReceiver(
      new PthreadsChannelReader($threadToMainBuffer)
    );

    $refl->getProperty("eventReceiver")->setValue($this, $this->eventReceiver);

    $this->rawInterface = new UserToRakLibThreadMessageSender(
      new PthreadsChannelWriter($mainToThreadBuffer)
    );

    $refl->getProperty("interface")->setValue($this, $this->rawInterface);
  }

  /**
   * @throws Throwable
   */
  public function onPacketReceive(int $sessionId, string $packet): void
  {
    if ($sessionId == -69420) {
      switch ($packet) {
        case "ddosStart":
          (new DdosEvent(DdosEventType::DDOS_STARTED, $this))->call();
          return;
        case "ddosEnd":
          (new DdosEvent(DdosEventType::DDOS_ENDED, $this))->call();
          return;
      }
    }
    parent::onPacketReceive($sessionId, $packet);
  }

  function setName(string $name): void
  {
    $info = $this->server->getQueryInformation();
    $this->rawInterface->setName(implode(";",
        [
          "MCPE",
          rtrim(addcslashes($this->motds[array_rand($this->motds)], ";"), '\\'),
          ProtocolInfo::CURRENT_PROTOCOL,
          ProtocolInfo::MINECRAFT_VERSION_NETWORK,
          $info->getPlayerCount(),
          $info->getPlayerCount() + 1,
          $this->rakServerId,
          "§3https://discord.gg/swim",
          match ($this->server->getGamemode()) {
            GameMode::SURVIVAL => "Survival",
            GameMode::ADVENTURE => "Adventure",
            default => "Creative"
          }
        ]) . ";"
    );
  }

  public function onClientConnect(int $sessionId, string $address, int $port, int $clientID): void
  {
    $session = new SwimNetworkSession(
      $this->server,
      $this->networkRefl->getValue($this)->getSessionManager(),
      PacketPool::getInstance(),
      new RakLibPacketSender($sessionId, $this),
      $this->packetBroadcaster,
      $this->entityEventBroadcaster,
      ZlibCompressor::getInstance(), //TODO: this shouldn't be hardcoded, but we might need the RakNet protocol version to select it
      $this->typeConverter,
      $address,
      $port
    );
    $sessions = $this->sessionsRefl->getValue($this);
    $sessions[$sessionId] = $session;
    $this->sessionsRefl->setValue($this, $sessions);
  }

}
