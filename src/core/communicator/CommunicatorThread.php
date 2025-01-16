<?php

namespace core\communicator;

use core\communicator\packet\ServerInfoPacket;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use Socket;
use Throwable;

class CommunicatorThread extends Thread
{

  public const TPS = 100;
  public const TIME_PER_TICK = 1 / self::TPS;

  private bool $shutdown = false;
  private bool $shutdownNextTick = false;

  private Socket|false $socket;

  public function __construct(
    protected ThreadSafeArray         $mainToThreadBuffer,
    protected ThreadSafeArray         $threadToMainBuffer,
    protected SleeperHandlerEntry     $sleeperEntry,
    private readonly ThreadSafeLogger $logger,
    private readonly string           $regionName,
    private readonly string           $serverIp,
    private readonly int              $serverPort,
  )
  {

  }

  protected function onRun(): void
  {
    gc_enable();
    ini_set("display_errors", '1');
    ini_set("display_startup_errors", '1');
    $sleeperNotifier = $this->sleeperEntry->createNotifier();
    $pthreadReader = new PthreadsChannelReader($this->mainToThreadBuffer);
    $pthreadWriter = new SnoozeAwarePthreadsChannelWriter($this->threadToMainBuffer, $sleeperNotifier);
    $this->runClient($pthreadReader, $pthreadWriter);
  }

  private function connect()
  {
    $this->logger->info("Connecting to " . $this->serverIp);
    $this->socket = socket_create(str_contains($this->serverIp, ":") ? AF_INET6 : AF_INET, SOCK_STREAM, SOL_TCP);
    if ($this->socket === false) {
      $this->logger->error("Failed to create socket: " . socket_strerror(socket_last_error()));
      return;
    }
    try {
      $result = socket_connect($this->socket, $this->serverIp, $this->serverPort);
      if ($result === false) {
        $this->logger->error("Failed to connect: " . socket_strerror(socket_last_error($this->socket)));
        return;
      }
      socket_set_nonblock($this->socket);
      $this->logger->info("Connected to " . $this->serverIp);

      $pk = new ServerInfoPacket;
      $pk->regionName = $this->regionName;
      socket_write($this->socket, $pk->encodeToString());
    } catch (Throwable $e) {
      $this->logger->info("Failed to connect: " . $e->getMessage());
    }
  }

  private function runClient(PthreadsChannelReader $pthreadReader, SnoozeAwarePthreadsChannelWriter $pthreadWriter): void
  {
    $this->connect();
    $this->tickProcesser($pthreadReader, $pthreadWriter);
  }

  private function tickProcesser(PthreadsChannelReader $pthreadReader, SnoozeAwarePthreadsChannelWriter $pthreadWriter): void
  {
    do {
      $start = microtime(true);
      $this->tick($pthreadReader, $pthreadWriter);
      $time = microtime(true) - $start;
      if ($time < self::TIME_PER_TICK) {
        @time_sleep_until(microtime(true) + self::TIME_PER_TICK - $time);
      }
    } while (!$this->shutdown);
  }

  public function close(): void
  {
    $this->shutdownNextTick = true;
  }

  private function reconnect(string $data, bool $wait = false): void
  {
    if ($this->shutdown) {
      return;
    }
    if ($wait) {
      $this->logger->warning("Connection lost, attempting reconnect in 5 seconds");
      sleep(5);
      if ($this->shutdownNextTick) {
        $this->shutdown = true;
        socket_close($this->socket);
        return;
      }
    }
    $this->logger->warning("Connection lost, attempting reconnect");
    socket_close($this->socket);
    $this->connect();
    if ($data !== "") {
      try {
        socket_write($this->socket, $data);
      } catch (Throwable $e) {
        $this->reconnect($data, true);
      }
    }
  }

  private function tick(PthreadsChannelReader $pthreadReader, SnoozeAwarePthreadsChannelWriter $pthreadWriter): void
  {
    while ($in = $pthreadReader->read()) {
      try {
        if (socket_write($this->socket, $in) === false) {
          $this->reconnect($in);
        }
      } catch (Throwable $e) {
        $this->reconnect($in);
      }
    }

    try {
      while ($buf = socket_read($this->socket, 65535)) {
        $pthreadWriter->write($buf);
      }
    } catch (Throwable $e) {
      $this->reconnect("", true);
    }
    if ($this->shutdownNextTick) {
      $this->shutdown = true;
      socket_close($this->socket);
    }
  }

}
