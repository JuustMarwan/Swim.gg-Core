<?php

namespace core\utils\raklib;

use Exception;
use Logger;
use raklib\generic\DisconnectReason;
use raklib\generic\ReceiveReliabilityLayer;
use raklib\generic\Session;
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectedPong;
use raklib\protocol\ConnectionRequest;
use raklib\protocol\ConnectionRequestAccepted;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NewIncomingConnection;
use raklib\protocol\Packet;
use raklib\protocol\PacketReliability;
use raklib\protocol\PacketSerializer;
use raklib\server\Server;
use raklib\server\ServerSession;
use raklib\utils\InternetAddress;
use raklib\generic\SendReliabilityLayer;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

class SwimServerSession extends ServerSession
{

  private const DATAGRAM_MTU_OVERHEAD = 36 + Datagram::HEADER_SIZE;
  public static ReflectionProperty $recvLayer;
  public static ReflectionProperty $sendLayer;
  public static ReflectionProperty $sendLayerMtu;
  public static ReflectionProperty $sendLayerMaxDatagram;
  public static ReflectionMethod $handlePong;
  public static ReflectionMethod $handleRemoteDisconnect;


  public static ReflectionProperty $recvHighestSeq;
  public static ReflectionProperty $recvWindowStart;
  public static ReflectionProperty $recvWindowEnd;


  private ReceiveReliabilityLayer $recvLayerClass;


  private int $cookie;
  private int $mtuSize;

  public function __construct(
    private Server  $server,
    Logger          $logger,
    InternetAddress $address,
    int             $clientId,
    int             $mtuSize,
    int             $internalId,
    int             $recvMaxSplitParts = self::DEFAULT_MAX_SPLIT_PART_COUNT,
    int             $recvMaxConcurrentSplits = self::DEFAULT_MAX_CONCURRENT_SPLIT_COUNT
  )
  {
    if (!isset(self::$recvLayer) || !isset(self::$sendLayer) || !isset(self::$handlePong) || !isset(self::$handleRemoteDisconnect) || !isset(self::$sendLayerMtu) || !isset(self::$sendLayerMaxDatagram)) {
      $refl = new ReflectionClass(Session::class);
      self::$sendLayer = $refl->getProperty("sendLayer");
      self::$sendLayerMtu = (new ReflectionClass(SendReliabilityLayer::class))->getProperty("mtuSize");
      self::$sendLayerMaxDatagram = (new ReflectionClass(SendReliabilityLayer::class))->getProperty("maxDatagramPayloadSize");
      self::$recvLayer = $refl->getProperty("recvLayer");
      self::$handlePong = $refl->getMethod("handlePong");
      self::$handleRemoteDisconnect = $refl->getMethod("handleRemoteDisconnect");

      self::$recvHighestSeq = (new ReflectionClass(ReceiveReliabilityLayer::class))->getProperty("highestSeqNumber");
      self::$recvWindowStart = (new ReflectionClass(ReceiveReliabilityLayer::class))->getProperty("windowStart");
      self::$recvWindowEnd = (new ReflectionClass(ReceiveReliabilityLayer::class))->getProperty("windowEnd");
    }
    $this->mtuSize = $mtuSize;
    $this->lastSendTime = microtime(true);
    parent::__construct($server, $logger, $address, $clientId, $mtuSize, $internalId, $recvMaxSplitParts, $recvMaxConcurrentSplits);
    $this->recvLayerClass = new ReceiveReliabilityLayer(
      $logger,
      function (EncapsulatedPacket $pk): void {
        $this->handleEncapsulatedPacketRoute($pk);
      },
      function (AcknowledgePacket $pk): void {
        $this->sendPacket($pk);
      },
      $recvMaxSplitParts,
      $recvMaxConcurrentSplits
    );
    self::$recvLayer->setValue($this, $this->recvLayerClass);
  }

  private float $lastSendTime = 0;

  public function update(float $time): void
  {
    if (!isset($this->cookie) && $time - $this->lastSendTime > 2) {
      if ($this->mtuSize <= Session::MIN_MTU_SIZE) {
        return;
      }
      $this->mtuSize = max(Session::MIN_MTU_SIZE, $this->mtuSize - 300);
      self::$sendLayerMtu->setValue(self::$sendLayer->getValue($this), $this->mtuSize);
      self::$sendLayerMaxDatagram->setValue(self::$sendLayer->getValue($this), $this->mtuSize - self::DATAGRAM_MTU_OVERHEAD);
      print("Lowered client $this->address MTU size to $this->mtuSize\n");
      $this->server->sendPacket(MTUOpenConnectionReply2::create($this->server->getID(), $this->address, $this->mtuSize, false, $this->server->ipHeaderSize), $this->address);
      $this->lastSendTime = $time;
    }

    $highestSeqNumber = self::$recvHighestSeq->getValue($this->recvLayerClass);
    $windowStart = self::$recvWindowStart->getValue($this->recvLayerClass);
    $windowEnd = self::$recvWindowEnd->getValue($this->recvLayerClass);

    parent::update($time);
    $this->adjustWindow($highestSeqNumber, $windowStart, $windowEnd);
  }

  public function adjustWindow(int $highestSeqNumber, int $windowStart, int $windowEnd)
  {
    $diff = max(-1, $highestSeqNumber - ReceiveReliabilityLayer::$WINDOW_SIZE / 4) - $windowStart + 1;
    assert($diff >= 0);
    if ($diff > 0) {
      //Move the receive window to account for packets we either received or are about to NACK
      //we ignore any sequence numbers that we sent NACKs for, because we expect the client to resend them
      //when it gets a NACK for it

      $windowStart += $diff;
      $windowEnd += $diff;

    }
    //print("start: $windowStart end: $windowEnd, highest: $highestSeqNumber\n");
    self::$recvWindowStart->setValue($this->recvLayerClass, $windowStart);
    self::$recvWindowEnd->setValue($this->recvLayerClass, $windowEnd);
  }


  /**
   * @throws Exception
   */
  protected function handleConnectionPacket(string $packet): void
  {
    $id = ord($packet[0]);
    if ($id === MessageIdentifiers::ID_CONNECTION_REQUEST) {
      $dataPacket = new ConnectionRequest();
      $dataPacket->decode(new PacketSerializer($packet));
      if (!isset($this->cookie)) {
        $this->cookie = random_int(0, PHP_INT_MAX);
      }
      $this->queueConnectedPacket(ConnectionRequestAccepted::create(
        $this->address,
        [],
        $dataPacket->sendPingTime,
        $this->cookie
      ), PacketReliability::UNRELIABLE, 0, true);
    } elseif ($id === MessageIdentifiers::ID_NEW_INCOMING_CONNECTION) {
      $dataPacket = new NewIncomingConnection();
      $dataPacket->decode(new PacketSerializer($packet));
      if ($dataPacket->sendPingTime != $this->cookie) {
        $this->forciblyDisconnect(DisconnectReason::SERVER_DISCONNECT);
        return;
      }
      if (abs(microtime(true) * 1000 - $dataPacket->sendPongTime) < 100000) {
        print("Client $this->address does not have valid timestamp\n");
        $this->forciblyDisconnect(DisconnectReason::SERVER_DISCONNECT);
        return;
      }
      //var_dump($dataPacket);
      //var_dump($this->cookie);
      if ($dataPacket->address->getPort() === $this->server->getPort() or !$this->server->portChecking) {
        $this->state = Session::STATE_CONNECTED; //FINALLY!
        $this->server->openSession($this);

        //$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime); //can't use this due to system-address count issues in MCPE >.<
        $this->sendPing();
      }
    }
  }

  /**
   * @throws ReflectionException
   * @throws Exception
   */
  private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet): void
  {
    $id = ord($packet->buffer[0]);
    if ($id < MessageIdentifiers::ID_USER_PACKET_ENUM) { //internal data packet
      if ($this->state === Session::STATE_CONNECTING) {
        $this->handleConnectionPacket($packet->buffer);
      } elseif ($id === MessageIdentifiers::ID_DISCONNECTION_NOTIFICATION) {
        self::$handleRemoteDisconnect->invoke($this);
      } elseif ($id === MessageIdentifiers::ID_CONNECTED_PING) {
        $dataPacket = new ConnectedPing();
        $dataPacket->decode(new PacketSerializer($packet->buffer));
        $this->queueConnectedPacket(ConnectedPong::create(
          $dataPacket->sendPingTime,
          $this->getRakNetTimeMS()
        ), PacketReliability::UNRELIABLE, 0);
      } elseif ($id === MessageIdentifiers::ID_CONNECTED_PONG) {
        $dataPacket = new ConnectedPong();
        $dataPacket->decode(new PacketSerializer($packet->buffer));

        self::$handlePong->invoke($this, $dataPacket->sendPingTime, $dataPacket->sendPongTime);
      }
    } elseif ($this->state === Session::STATE_CONNECTED) {
      $this->onPacketReceive($packet->buffer);
    } else {
      //$this->logger->notice("Received packet before connection: " . bin2hex($packet->buffer));
    }
  }

  public array $recv = [];
  public array $send = [];

  private int $spoofAmt = 0;
  private int $spoofJitter = 0;
  private int $totalSpoof = 0;

  public function setSpoofAmt(int $spoofAmt)
  {
    $this->spoofAmt = $spoofAmt;
    $this->totalSpoof = $spoofAmt;
  }

  public function setSpoofJitter(int $spoofJitter)
  {
    $this->spoofJitter = $spoofJitter;
    $this->totalSpoof = $this->spoofAmt;
  }

  public function getSpoofJitter(): int
  {
    return $this->spoofJitter;
  }

  public function getSpoofAmt(): float
  {
    return max($this->spoofAmt, $this->spoofJitter);
  }

  public function getTotalSpoofAmt(): float
  {
    if ($this->spoofJitter == 0) return $this->getSpoofAmt() / 2000;
    $jitterRange = (int)($this->getSpoofJitter() / 2);
    $this->totalSpoof = (int)max($this->getSpoofAmt() - $this->spoofJitter * 2, min($this->totalSpoof + mt_rand(-$jitterRange, $jitterRange), $this->getSpoofAmt() + $this->spoofJitter * 2));
    if ($this->totalSpoof < $this->getSpoofAmt()) {
      $this->totalSpoof += (int)(($this->getSpoofAmt() - $this->totalSpoof) / $this->spoofJitter * 2.2);
    } else {
      $this->totalSpoof -= (int)(($this->getSpoofAmt() - $this->spoofAmt) / $this->spoofJitter * 2.2);
    }
    return max(0, $this->totalSpoof) / 2000;
  }

  public function getSendEntries(): array
  {
    return $this->send;
  }

  public function getRecvEntries(): array
  {
    return $this->recv;
  }

  public function addSendEntry(float $time, Packet $packet): void
  {
    $this->send[] = [$time, $packet];
  }

  public function addRecvEntry(float $time, Packet $packet): void
  {
    $this->recv[] = [$time, $packet];
  }

  public function removeSendEntry(int $index)
  {
    unset($this->send[$index]);
  }

  public function removeRecvEntry(int $index)
  {
    unset($this->recv[$index]);
  }

  public function cleanEntries(bool $sendEntries, bool $recvEntries): void
  {
    if ($sendEntries) $this->send = array_values($this->send);
    if ($recvEntries) $this->recv = array_values($this->recv);
  }

}
