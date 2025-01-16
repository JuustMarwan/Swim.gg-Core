<?php

namespace core\utils\raklib;

use DateTime;
use Logger;
use pocketmine\utils\BinaryDataException;
use raklib\generic\DisconnectReason;
use raklib\generic\SocketException;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\NACK;
use raklib\protocol\Packet;
use raklib\protocol\PacketSerializer;
use raklib\server\ProtocolAcceptor;
use raklib\server\Server;
use raklib\server\ServerEventListener;
use raklib\server\ServerEventSource;
use raklib\server\ServerSession;
use raklib\server\ServerSocket;
use raklib\utils\ExceptionTraceCleaner;
use raklib\utils\InternetAddress;
use ReflectionException;
use ReflectionMethod;

class SwimRakLibRawServer extends Server
{

  private const MAX_UNCONNECTED_PPS = 15000;
  private const PPS_CLEAR_INTERVAL = 10;
  private const MAX_SHUTDOWN_MS = 1000;

  private const ACTUAL_MAX_UNCONNECTED_PPS = self::MAX_UNCONNECTED_PPS * self::PPS_CLEAR_INTERVAL;

  private const RAKLIB_TPS = 1000;
  private const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

  private bool $isBeingDdosed = false;
  private bool $forcedAntiDdos = false;
  public bool $blockNewConnections = false;
  private int $dosSec = 0;
  private int $lastBlockTime = 0;
  private int $unconnectedPps = 0;
  private ServerEventSource $eventSource;
  private ServerEventListener $eventListener;

  private ReflectionMethod $removeSessionInternal;
  private ReflectionMethod $checkSessions;

  public int $ipHeaderSize;

  /**
   * @throws ReflectionException
   */
  public function __construct(int $serverId, Logger $logger, ServerSocket $socket, int $maxMtuSize, ProtocolAcceptor $protocolAcceptor, ServerEventSource $eventSource, ServerEventListener $eventListener, ExceptionTraceCleaner $traceCleaner)
  {
    $this->eventSource = $eventSource;
    $this->eventListener = $eventListener;
    parent::__construct($serverId, $logger, $socket, $maxMtuSize, $protocolAcceptor, $eventSource, $eventListener, $traceCleaner);
    $this->removeSessionInternal = (new ReflectionMethod(Server::class, "removeSessionInternal"));
    $this->checkSessions = (new ReflectionMethod(Server::class, "checkSessions"));
    $this->packetLimit = 1000;
    $this->unconnectedMessageHandler = new SecureUnconnectedMessageHandler($this, $protocolAcceptor);
    $this->logger = new StubLogger();
    $this->ipHeaderSize = str_contains($socket->getBindAddress()->getIp(), ":") ? 40 : 20;
  }

  public function tickProcessor(): void
  {
    $start = microtime(true);

    /*
     * The below code is designed to allow co-op between sending and receiving to avoid slowing down either one
     * when high traffic is coming either way. Yielding will occur after 50 messages.
     */
    do {
      $stream = !$this->shutdown;
      for ($i = 0; $i < 50 && $stream && !$this->shutdown; ++$i) { //if we received a shutdown event, we don't care about any more messages from the event source
        $stream = $this->eventSource->process($this);
      }

      $socket = true;
      for ($i = 0; $i < 50 && $socket; ++$i) {
        $socket = $this->receivePacket();
      }
    } while ($stream || $socket);

    $this->tick();

    $time = microtime(true) - $start;
    if ($time < self::RAKLIB_TIME_PER_TICK) {
      @time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
    }
  }

  public function isShuttingDown(): bool
  {
    return $this->shutdown;
  }

  public function waitShutdown(): void
  {
    $shutdownStart = microtime(true) * 1000;
    $this->shutdown = true;

    while ($this->eventSource->process($this)) {
      if (microtime(true) * 1000 - $shutdownStart > self::MAX_SHUTDOWN_MS) {
        $this->socket->close();
        $this->logger->warning("Force shutdown");
        return;
      }
      //Ensure that any late messages are processed before we start initiating server disconnects, so that if the
      //server implementation used a custom disconnect mechanism (e.g. a server transfer), we don't break it in
      //race conditions.
    }

    foreach ($this->sessions as $session) {
      $session->forciblyDisconnect(DisconnectReason::SERVER_SHUTDOWN);
    }

    while (count($this->sessions) > 0) {
      $this->tickProcessor();
      if (microtime(true) * 1000 - $shutdownStart > self::MAX_SHUTDOWN_MS) {
        $this->socket->close();
        $this->logger->warning("Force shutdown");
        return;
      }
    }

    $this->socket->close();
    $this->logger->debug("Graceful shutdown complete");
  }

  private function tick(): void
  {
    $time = microtime(true);
    foreach ($this->sessions as $session) {
      $cleanRecv = false;
      $cleanSend = false;
      foreach ($session->getRecvEntries() as $e => $pk) {
        if ($pk[0] < $time) {
          $cleanRecv = true;
          $session->handlePacket($pk[1]);
          $session->removeRecvEntry($e);
        }
      }
      foreach ($session->getSendEntries() as $e => $pk) {
        if ($pk[0] < $time) {
          $cleanSend = true;
          $this->sendPacketInternal($pk[1], $session->getAddress());
          $session->removeSendEntry($e);
        }
      }
      $session->cleanEntries($cleanSend, $cleanRecv);
      $session->update($time);
      if ($session->isFullyDisconnected()) {
        $this->removeSessionInternal->invokeArgs($this, [$session]);
      }
    }

    if ($this->ticks % (self::RAKLIB_TPS / 10) == 0)
      $this->ipSec = [];
    if ($this->ticks % (self::RAKLIB_TPS * self::PPS_CLEAR_INTERVAL) == 0) {
      if ($this->isBeingDdosed && time() - $this->lastBlockTime > 20 && $this->unconnectedPps * 2 < self::ACTUAL_MAX_UNCONNECTED_PPS) {
        $this->unconnectedMessageHandler->trustedAddresses = [];
        $this->isBeingDdosed = false;
        $this->eventListener->onPacketReceive(-69420, "ddosEnd");
      }

      $this->unconnectedMessageHandler->trustedAddresses = [];
      $this->dosSec = 0;
      $this->unconnectedPps = 0;
    }

    if (!$this->shutdown && ($this->ticks % self::RAKLIB_TPS) === 0) {
      if ($this->sendBytes > 0 || $this->receiveBytes > 0) {
        $this->eventListener->onBandwidthStatsUpdate($this->sendBytes, $this->receiveBytes);
        $this->sendBytes = 0;
        $this->receiveBytes = 0;
      }

      if (count($this->block) > 0) {
        asort($this->block);
        $now = time();
        foreach ($this->block as $address => $timeout) {
          if ($timeout <= $now) {
            unset($this->block[$address]);
          } else {
            break;
          }
        }
      }
    }

    ++$this->ticks;
  }

  public function sendPacket(Packet $packet, InternetAddress $address): void
  {
    /** @var ?SwimServerSession */
    $session = $this->getSessionByAddress($address);
    if (!$session || $session->getSpoofAmt() == 0) {
      $this->sendPacketInternal($packet, $address);
      return;
    }
    $session->addSendEntry(microtime(true) + $session->getTotalSpoofAmt(), $packet);
  }

  public function sendPacketInternal(Packet $packet, InternetAddress $address): void
  {
    $out = new PacketSerializer();
    $packet->encode($out);
    try {
      $this->sendBytes += $this->socket->writePacket($out->getBuffer(), $address->getIp(), $address->getPort());
    } catch (SocketException $e) {
      $this->logger->debug($e->getMessage());
    }
  }

  private function receivePacket(): bool
  {
    try {
      $buffer = $this->socket->readPacket($addressIp, $addressPort);
    } catch (SocketException $e) {
      $error = $e->getCode();
      if ($error === SOCKET_ECONNRESET) { //client disconnected improperly, maybe crash or lost connection
        return true;
      }

      $this->logger->debug($e->getMessage());
      return false;
    }
    if ($buffer === null) {
      return false; //no data
    }
    $len = strlen($buffer);

    $this->receiveBytes += $len;
    if (isset($this->block[$addressIp])) {
      return true;
    }

    if (isset($this->ipSec[$addressIp])) {
      if (++$this->ipSec[$addressIp] >= $this->packetLimit) {
        $this->blockAddress($addressIp);
        return true;
      }
    } else {
      $this->ipSec[$addressIp] = 1;
    }

    if ($len < 1) {
      return true;
    }

    $address = new InternetAddress($addressIp, $addressPort, $this->socket->getBindAddress()->getVersion());
    try {
      $session = $this->getSessionByAddress($address);
      if ($session !== null) {
        /** @var SwimServerSession $session */
        $header = ord($buffer[0]);
        if (($header & Datagram::BITFLAG_VALID) !== 0) {
          if (($header & Datagram::BITFLAG_ACK) !== 0) {
            $packet = new ACK();
          } elseif (($header & Datagram::BITFLAG_NAK) !== 0) {
            $packet = new NACK();
          } else {
            $packet = new Datagram();
          }
          $packet->decode(new PacketSerializer($buffer));
          if ($session->getSpoofAmt() == 0) {
            $session->handlePacket($packet);
          } else {
            $session->addRecvEntry(microtime(true) + $session->getTotalSpoofAmt(), $packet);
          }
          return true;
        } elseif ($session->isConnected()) {
          //allows unconnected packets if the session is stuck in DISCONNECTING state, useful if the client
          //didn't disconnect properly for some reason (e.g. crash)
          $this->logger->debug("Ignored unconnected packet from $address due to session already opened (0x" . bin2hex($buffer[0]) . ")");
          return true;
        }
      }

      if (++$this->unconnectedPps > self::ACTUAL_MAX_UNCONNECTED_PPS && !$this->isBeingDdosed) {
        $this->isBeingDdosed = true;
        $this->eventListener->onPacketReceive(-69420, "ddosStart");
      }

      if (!$this->shutdown) {
        if (!($handled = $this->unconnectedMessageHandler->handleRaw($buffer, $address))) {
          if (!$this->getIsBeingDdosed()) {
            foreach ($this->rawPacketFilters as $pattern) {
              if (preg_match($pattern, $buffer) > 0) {
                $handled = true;
                $this->eventListener->onRawPacketReceive($address->getIp(), $address->getPort(), $buffer);
                break;
              }
            }
          }
        }

        if (!$handled) {
          $this->logger->debug("Ignored packet from $address due to no session opened (0x" . bin2hex($buffer[0]) . ")");
        }
      }
    } catch (BinaryDataException $e) {
      $this->blockAddress($address->getIp(), 5, true);
    }

    return true;
  }

  public function blockAddress(string $address, int $timeout = 300, bool $ddos = false): void
  {
    if ($address === "enableForcedAD") {
      $this->forcedAntiDdos = true;
      return;
    }
    if ($address === "enableForcedAD true") {
      $this->forcedAntiDdos = true;
      $this->blockNewConnections = true;
      return;
    }
    if ($address === "disableForcedAD") {
      $this->forcedAntiDdos = false;
      $this->blockNewConnections = false;
      return;
    }
    if (str_contains($address, " ")) {
      $session = $this->sessionsByAddress[$address] ?? null;
      if (!$session)
        return;
      [$ping, $jitter] = morton2d_decode($timeout);
      if ($ping < 0 || $jitter < 0) {
        return;
      }
      $session->setSpoofAmt($ping);
      $session->setSpoofJitter($jitter);
      return;
    }
    $final = time() + $timeout;
    if (!isset($this->block[$address]) || $timeout === -1) {
      if ($timeout === -1) {
        $final = PHP_INT_MAX;
      } else {
        if ($ddos) {
          $this->dosSec++;
          if ($this->dosSec > 20 && !$this->isBeingDdosed) {
            $this->isBeingDdosed = true;
            $this->eventListener->onPacketReceive(-69420, "ddosStart");
          }
          if ($this->isBeingDdosed) {
            $this->lastBlockTime = time();
          } else {
            $time = new DateTime();
            print ("\u{001b}[38;5;87m" . $time->format("[H:i:s.v]") . " [AntiDDOS] Blocked $address for $timeout seconds\u{001b}[0m\n");
          }
        } else {
          $time = new DateTime();
          print ("\u{001b}[38;5;87m" . $time->format("[H:i:s.v]") . " [NOTICE] Blocked $address for $timeout seconds\u{001b}[0m\n");
          //$this->logger->notice("Blocked $address for $timeout seconds");
        }
      }
      $this->block[$address] = $final;
    } elseif ($this->block[$address] < $final) {
      $this->block[$address] = $final;
    }
  }

  public function getIsBeingDdosed(): bool
  {
    return $this->isBeingDdosed || $this->forcedAntiDdos;
  }

  public function closeSession(int $sessionId): void
  {
    if (isset($this->sessions[$sessionId])) {
      foreach ($this->sessions[$sessionId]->getSendEntries() as $e => $pk) {
        $this->sendPacketInternal($pk[1], $this->sessions[$sessionId]->getAddress());
        $this->sessions[$sessionId]->removeSendEntry($e);
      }
      $this->sessions[$sessionId]->forciblyDisconnect(DisconnectReason::SERVER_DISCONNECT);
    }
  }

  /**
   * @throws ReflectionException
   */
  public function createSession(InternetAddress $address, int $clientId, int $mtuSize): ServerSession
  {
    $existingSession = $this->sessionsByAddress[$address->toString()] ?? null;
    if ($existingSession !== null) {
      $existingSession->forciblyDisconnect(DisconnectReason::CLIENT_RECONNECT);
      $this->removeSessionInternal->invokeArgs($this, [$existingSession]);
    }

    $this->checkSessions->invoke($this);

    while (isset($this->sessions[$this->nextSessionId])) {
      $this->nextSessionId++;
      $this->nextSessionId &= 0x7fffffff; //we don't expect more than 2 billion simultaneous connections, and this fits in 4 bytes
    }

    $session = new SwimServerSession($this, $this->logger, clone $address, $clientId, $mtuSize, $this->nextSessionId);
    $this->sessionsByAddress[$address->toString()] = $session;
    $this->sessions[$this->nextSessionId] = $session;
    $this->logger->debug("Created session for $address with MTU size $mtuSize");

    return $session;
  }

}