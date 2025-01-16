<?php

namespace core\utils\raklib;

use GlobalLogger;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\RakLibServer;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use raklib\server\ipc\RakLibToUserThreadMessageSender;
use raklib\server\ipc\UserToRakLibThreadMessageReceiver;
use raklib\server\ServerSocket;
use raklib\utils\ExceptionTraceCleaner;
use ReflectionException;

class SwimRakLibServer extends RakLibServer
{

  /**
   * @throws ReflectionException
   */
  protected function onRun(): void
  {
    gc_enable();
    ini_set("display_errors", '1');
    ini_set("display_startup_errors", '1');
    GlobalLogger::set($this->logger);

    $socket = new ServerSocket($this->address->deserialize());
    $manager = new SwimRakLibRawServer(
      $this->serverId,
      $this->logger,
      $socket,
      $this->maxMtuSize,
      new MultiProtocolAcceptor($this->protocolVersion, [10, $this->protocolVersion]),
      new UserToRakLibThreadMessageReceiver(new PthreadsChannelReader($this->mainToThreadBuffer)),
      new RakLibToUserThreadMessageSender(new SnoozeAwarePthreadsChannelWriter($this->threadToMainBuffer, $this->sleeperEntry->createNotifier())),
      new ExceptionTraceCleaner($this->mainPath)
    );

    // synchronized and notify methods not found
    $this->synchronized(function (): void {
      $this->ready = true;
      $this->notify();
    });

    while (!$this->isKilled) {
      $manager->tickProcessor();
    }
    $manager->waitShutdown();
  }

}