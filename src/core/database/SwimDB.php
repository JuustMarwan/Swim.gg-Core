<?php

namespace core\database;

use core\database\queries\TableManager;
use core\SwimCore;
use core\utils\TimeHelper;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;

class SwimDB
{

  private static DataConnector $DBC;

  public static function initialize(SwimCore $core): void
  {
    $databaseConf = $core->getSwimConfig()->database;

    // establish the database connection with the database info
    self::$DBC = libasynql::create($core, ["type" => "mysql", "mysql" => ["host" => $databaseConf->host,
      "username" => $databaseConf->username, "password" => $databaseConf->password, "schema" => $databaseConf->schema,
      "port" => $databaseConf->port, "worker-limit" => $databaseConf->workerLimit]], ["mysql" => "mysql.sql"]);
    // once we have made it, create the tables
    TableManager::createTables();

    // start up the keep alive task to ping the database every minute
    $core->getScheduler()->scheduleRepeatingTask(new KeepAlive(self::$DBC), TimeHelper::minutesToTicks(1));
  }

  public static function getDatabase(): DataConnector
  {
    return self::$DBC;
  }

  public static function close(): void
  {
    self::$DBC->close();
  }

}

