<?php

namespace core;

use core\communicator\Communicator;
use core\communicator\packet\types\DisconnectReason;
use core\database\SwimDB;
use core\listeners\PlayerListener;
use core\listeners\WorldListener;
use core\systems\SystemManager;
use core\tasks\RandomMessageTask;
use core\tasks\SystemUpdateTask;
use core\utils\loaders\CommandLoader;
use core\utils\config\ConfigMapper;
use core\utils\config\RegionInfo;
use core\utils\config\SwimConfig;
use core\utils\loaders\CustomItemLoader;
use core\utils\raklib\SwimRakLibInterface;
use core\utils\security\IpParse;
use core\utils\loaders\WorldLoader;
use core\utils\VoidGenerator;
use CortexPE\Commando\exception\HookAlreadyRegistered;
use JsonException;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\event\EventPriority;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\utils\ServerKiller;
use pocketmine\utils\SignalHandler;
use pocketmine\utils\TextFormat;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\World;
use ReflectionException;
use Symfony\Component\Filesystem\Path;
use pocketmine\ServerProperties;

class SwimCore extends PluginBase
{

  public static bool $AC = true;
  public static bool $DEBUG = false;
  public static bool $RANKED = true;

  public static string $assetFolder; // holds our assets for our custom loaded entities geometry and skin
  public static string $dataFolder; // the plug-in data folder that gets generated
  public static string $rootFolder;
  public static string $customDataFolder;
  public static bool $isNetherGames = false;
  public bool $shuttingDown = false;
  public static bool $blobCacheOn = true;
  public bool $deltaOn = false;

  private SystemManager $systemManager;
  private CommandLoader $commandLoader;
  private SwimConfig $swimConfig;
  private RegionInfo $regionInfo;

  private WorldListener $worldListener;
  private PlayerListener $playerListener;

  private Communicator $communicator;

  /**
   * @throws JsonException
   * @throws HookAlreadyRegistered
   * @throws ReflectionException
   */
  public function onEnable(): void
  {
    self::$isNetherGames = method_exists(NetworkSession::class, "getProtocolId");
    // set instance
    SwimCoreInstance::setInstance($this);

    // set up the server appearance on the main menu based on whitelisted or not
    $this->MenuAppearance();

    // set up the config
    $this->swimConfig = new SwimConfig;
    $confMapper = new ConfigMapper($this, $this->swimConfig);
    $confMapper->load();
    $confMapper->save(); // add missing fields to config

    $this->regionInfo = new RegionInfo;
    $regionConfFile = Path::join(SwimCore::$dataFolder, "region.yml");
    $regionInfoConf = new Config($regionConfFile);
    $regionMapper = new ConfigMapper($regionInfoConf, $this->regionInfo);
    $regionMapper->load();
    $regionMapper->save();

    // load the worlds
    WorldLoader::loadWorlds(self::$rootFolder);

    // set up the system manager
    $this->systemManager = new SystemManager($this);
    $this->systemManager->init();

    // set up the command loader and load the commands we want and don't want
    $this->commandLoader = new CommandLoader($this);
    $this->commandLoader->setUpCommands();

    // Load all of our custom items and vanilla replacements and removals too for edu items
    CustomItemLoader::registerCustoms();

    // set the database connection
    SwimDB::initialize($this);

    // set the server's listeners
    $this->setListeners();

    // schedule server's tasks
    $this->registerTasks();

    // set up our rak lib interface
    $this->setUpRakLib();

    // register inv menu (thanks muqsit)
    if (!InvMenuHandler::isRegistered()) {
      InvMenuHandler::register($this);
    }

    // set up signal handler
    $this->setUpSignalHandler();

    $this->communicator = new Communicator($this);

    // Disable the garbage collector, this is a HUGE performance boost that literally made Divinity playable and relatively a smooth server.
    // We are really going to have to make sure we collect our resources properly.
    gc_disable();
  }

  private function registerTasks(): void
  {
    $this->getScheduler()->scheduleRepeatingTask(new SystemUpdateTask($this), 1); // update system every tick
    $this->getScheduler()->scheduleRepeatingTask(new RandomMessageTask, 2400); // random message in server every 2 minutes
  }

  public function getHubWorld(): ?World
  {
    return $this->getServer()->getWorldManager()->getWorldByName($this->getRegionInfo()->isHub() ? "lobby" : "hub");
  }

  private function setUpSignalHandler(): void
  {
    new SignalHandler(function () {

      $this->getLogger()->info("got signal, shutting down...");
      $this->getLogger()->info("disconnecting players...");
      $this->shuttingDown = true;

      foreach ($this->getServer()->getOnlinePlayers() as $player) {
        $player->kick(TextFormat::RED . "Server was shutdown by an admin.");
      }

      $this->getScheduler()->scheduleDelayedTask(new class($this) extends Task {

        private SwimCore $swimCore;

        public function __construct(SwimCore $xenonCore)
        {
          $this->swimCore = $xenonCore;
        }

        public function onRun(): void
        {
          $this->swimCore->getLogger()->info("stopping server...");
          $this->swimCore->getServer()->shutdown();
        }

      }, 5); // give clients time to disconnect
    });
  }

  /**
   * @throws ReflectionException
   */
  private function setUpRakLib(): void
  {
    $typeConverter = TypeConverter::getInstance();
    $packetBroadcaster = new StandardPacketBroadcaster($this->getServer(), method_exists(TypeConverter::class, "getProtocolId") ? $typeConverter->getProtocolId() : ProtocolInfo::CURRENT_PROTOCOL);
    $entityEventBroadcaster = new StandardEntityEventBroadcaster($packetBroadcaster, $typeConverter);
    $this->getServer()->getNetwork()->registerInterface(new SwimRakLibInterface($this->getServer(), $this->getServer()->getIp(), $this->getServer()->getPort(), false, $packetBroadcaster, $entityEventBroadcaster, $typeConverter, $this->swimConfig->motds));
    if ($this->getServer()->getConfigGroup()->getConfigBool(ServerProperties::ENABLE_IPV6, true)) {
      $this->getServer()->getNetwork()->registerInterface(new SwimRakLibInterface($this->getServer(), $this->getServer()->getIpV6(), $this->getServer()->getPortV6(), true, $packetBroadcaster, $entityEventBroadcaster, $typeConverter, $this->swimConfig->motds));
    }

    $this->getServer()->getPluginManager()->registerEvent(NetworkInterfaceRegisterEvent::class, function (NetworkInterfaceRegisterEvent $event) {
      $interface = $event->getInterface();
      if (($interface instanceof RakLibInterface || $interface instanceof DedicatedQueryNetworkInterface) && !$event instanceof SwimRakLibInterface) {
        $event->cancel();
      }
    }, EventPriority::NORMAL, $this);
  }

  public function onLoad(): void
  {
    // set up asset and data folder
    $this->setDataAssetFolderPaths();

    // register the void generator SwimCore has built in
    GeneratorManager::getInstance()->addGenerator(VoidGenerator::class, "void", fn() => null, true);
  }

  // close the connection to the database
  protected function onDisable(): void
  {
    $this->communicator->close($this->shuttingDown ? DisconnectReason::SERVER_SHUTDOWN : DisconnectReason::SERVER_CRASH);
    if (!$this->shuttingDown) {
      $this->shuttingDown = true;
      foreach ($this->getServer()->getOnlinePlayers() as $p) {
        $serverAddr = $p->getPlayerInfo()->getExtraData()["ServerAddress"] ?? "0.0.0.0:1";
        $parsedIp = IpParse::sepIpFromPort($serverAddr);
        $p->getNetworkSession()->transfer($parsedIp[0], $parsedIp[1]);
      }
    }

    SwimDB::close();

    $this->getLogger()->info("-disabled");
    // something is getting stuck so this is a hack fix to force close after 5 seconds
    $killer = new ServerKiller(5);
    $killer->start(0);
    $this->getServer()->getLogger()->setLogDebug(true);
  }

  public function getCommunicator(): Communicator
  {
    return $this->communicator;
  }

  private function setListeners(): void
  {
    $this->playerListener = new PlayerListener($this);
    $this->worldListener = new WorldListener($this);
    Server::getInstance()->getPluginManager()->registerEvents($this->playerListener, $this);
    Server::getInstance()->getPluginManager()->registerEvents($this->worldListener, $this);
  }

  private function setDataAssetFolderPaths(): void
  {
    self::$assetFolder = str_replace("\\", DIRECTORY_SEPARATOR,
      str_replace("/", DIRECTORY_SEPARATOR,
        Path::join($this->getFile(), "assets")));

    self::$dataFolder = str_replace("\\", DIRECTORY_SEPARATOR,
      str_replace("/", DIRECTORY_SEPARATOR,
        Path::join($this->getDataFolder())));

    self::$customDataFolder = str_replace("\\", DIRECTORY_SEPARATOR,
      str_replace("/", DIRECTORY_SEPARATOR,
        Path::join($this->getFile(), "data")));

    self::$rootFolder = dirname(self::$assetFolder, 3);
    echo("SwimCore asset folder: " . self::$assetFolder . "\n");
    echo("SwimCore data folder: " . self::$dataFolder . "\n");
    echo("SwimCore root folder: " . self::$rootFolder . "\n");
  }

  // toggles menu appearance based on white list
  private function MenuAppearance(): void
  {
    if (Server::getInstance()->hasWhitelist()) {
      Server::getInstance()->getNetwork()->setName("§r§cMaintenance");
    } else {
      Server::getInstance()->getNetwork()->setName(TextFormat::DARK_AQUA . TextFormat::BOLD . "SCRIMS");
    }
  }

  public function getSystemManager(): SystemManager
  {
    return $this->systemManager;
  }

  public function getCommandLoader(): CommandLoader
  {
    return $this->commandLoader;
  }

  public function getSwimConfig(): SwimConfig
  {
    return $this->swimConfig;
  }

  public function getRegionInfo(): RegionInfo
  {
    return $this->regionInfo;
  }

  public function getPlayerListener(): PlayerListener
  {
    return $this->playerListener;
  }

  public function getWorldListener(): WorldListener
  {
    return $this->worldListener;
  }

}
