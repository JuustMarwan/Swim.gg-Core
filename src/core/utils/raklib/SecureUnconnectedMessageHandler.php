<?php

namespace core\utils\raklib;

use Exception;
use raklib\generic\Session;
use raklib\protocol\IncompatibleProtocolVersion;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\PacketSerializer;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPong;
use raklib\server\ProtocolAcceptor;
use raklib\server\UnconnectedMessageHandler;
use raklib\utils\InternetAddress;
use ReflectionException;
use ReflectionMethod;

class SecureUnconnectedMessageHandler extends UnconnectedMessageHandler
{

  private string $salt;
  private int $lastUnconnectedPing = 0;
  public array $trustedAddresses = [];

  /**
   * @throws ReflectionException
   * @throws Exception
   */
  public function __construct(
    private SwimRakLibRawServer $server,
    private ProtocolAcceptor    $protocolAcceptor
  )
  {
    $this->salt = random_bytes(16);
    parent::__construct($server, $protocolAcceptor);
    $registerPk = new ReflectionMethod(UnconnectedMessageHandler::class, "registerPacket");
    $registerPk->invoke($this, MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2, SecureOpenConnectionRequest2::class);
  }

  private function ipTo32BitInt(string $ip): int
  {
    // Hash the IP address with salt using SHA-256
    $hashed = hash('sha256', $ip . $this->salt);

    // Take the first 8 characters of the hashed string
    $substring = substr($hashed, 0, 8);

    // Convert the hexadecimal string to a signed 32-bit integer
    $int = hexdec($substring);
    if ($int > 0x7FFFFFFF) {
      $int -= 0xFFFFFFFF + 1;
    }
    return (int)$int;
  }

  /**
   * @throws ReflectionException
   */
  public function handleRaw(string $payload, InternetAddress $address): bool
  {
    if ($this->server->blockNewConnections) {
      return true;
    }
    if ($this->server->getIsBeingDdosed() && !isset($this->trustedAddresses[$address->getIp()])) {
      $this->trustedAddresses[$address->getIp()] = true;
      return true;
    }

    if ($payload === "") {
      return false;
    }
    $pk = $this->getPacketFromPool($payload);
    if ($pk === null) {
      return false;
    }
    $reader = new PacketSerializer($payload);
    $pk->decode($reader);
    if (!$pk->isValid()) {
      return false;
    }
    $unread = false;
    if (!$reader->feof()) {
      $unread = true;
      //$remains = substr($reader->getBuffer(), $reader->getOffset());
      //$this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . get_class($pk) . " from $address");
    }
    return $this->handle($pk, $address, $unread);
  }

  private const ORANGE_PORT = 19142;

  /**
   * @throws ReflectionException
   */
  private function handle(OfflineMessage $packet, InternetAddress $address, bool $unreadBytes = false): bool
  {
    if ($packet instanceof UnconnectedPing) {
      if ($unreadBytes)
        return true;
      $name = $this->server->getName();
      if (abs(microtime(true) * 1000 - $packet->sendPingTime) < 100000) {
        $name .= "1;" . self::ORANGE_PORT . ";" . self::ORANGE_PORT . ";";
      }
      $this->server->sendPacketInternal(UnconnectedPong::create($packet->sendPingTime, $this->server->getID(), $name), $address);
    } elseif ($packet instanceof OpenConnectionRequest1) {
      if ($packet->mtuSize < Session::MIN_MTU_SIZE - $this->server->ipHeaderSize - 8) {
        return true;
      }
      if (!$this->protocolAcceptor->accepts($packet->protocol)) {
        $this->server->sendPacketInternal(IncompatibleProtocolVersion::create($this->protocolAcceptor->getPrimaryVersion(), $this->server->getID()), $address);
        $this->server->getLogger()->notice("Refused connection from $address due to incompatible RakNet protocol version (version $packet->protocol)");
      } else {
        //IP header size (20/40 bytes) + UDP header size (8 bytes)
        $this->server->sendPacketInternal(SecureOpenConnectionReply1::create($this->server->getID(), true, $packet->mtuSize + 28, $this->ipTo32BitInt($address->toString())), $address);
      }
    } elseif ($packet instanceof SecureOpenConnectionRequest2) {
      if ($packet->serverAddress->getPort() === $this->server->getPort() || !$this->server->portChecking) {
        if ($packet->handshakeId != $this->ipTo32BitInt($address->toString())) {
          print ("Client $address did not pass handshake\n");
          return true;
        }
        if ($packet->mtuSize < Session::MIN_MTU_SIZE) {
          $this->server->getLogger()->debug("Not creating session for $address due to bad MTU size $packet->mtuSize");
          return true;
        }
        $existingSession = $this->server->getSessionByAddress($address);
        if ($existingSession !== null && $existingSession->isConnected()) {
          //for redundancy, in case someone rips up Server - we really don't want connected sessions getting
          //overwritten
          $this->server->getLogger()->debug("Not creating session for $address due to session already opened");
          return true;
        }
        if ($this->server->isShuttingDown()) {
          $this->server->sendPacketInternal(NoFreeIncomingConnections::create($this->server->getID()), $address);
          return true;
        }
        $mtuSize = min($packet->mtuSize, $this->server->getMaxMtuSize()); //Max size, do not allow creating large buffers to fill server memory
        $this->server->sendPacketInternal(MTUOpenConnectionReply2::create($this->server->getID(), $address, $mtuSize, false, $this->server->ipHeaderSize), $address);
        $this->server->createSession($address, $packet->clientID, $mtuSize + 20 - $this->server->ipHeaderSize);
      } else {
        $this->server->getLogger()->debug("Not creating session for $address due to mismatched port, expected " . $this->server->getPort() . ", got " . $packet->serverAddress->getPort());
      }
    } else {
      return false;
    }

    return true;
  }

}