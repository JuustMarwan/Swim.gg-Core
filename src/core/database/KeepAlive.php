<?php

namespace core\database;

use pocketmine\scheduler\Task;
use poggit\libasynql\DataConnector;
use poggit\libasynql\SqlThread;

class KeepAlive extends Task
{

  private DataConnector $DBC;

  public function __construct(DataConnector $DBC)
  {
    $this->DBC = $DBC;
  }

  /*
  * @brief Called in a task every minute to ping the database to keep the connection alive
  */
  public function onRun(): void
  {
    // Perform a simple query like SELECT 1 to ping the database
    $this->DBC->executeImplRaw(
      [
        0 => "SELECT 1"
      ],
      [0 => []],
      [0 => SqlThread::MODE_GENERIC],
      function () {
      },
      null
    );
  }

}