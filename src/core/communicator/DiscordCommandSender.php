<?php

namespace core\communicator;

use core\communicator\packet\DiscordCommandMessagePacket;
use core\SwimCore;
use core\systems\player\components\Rank;
use InvalidArgumentException;
use pocketmine\command\CommandSender;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissibleBase;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\Server;

class DiscordCommandSender implements CommandSender
{

  use PermissibleDelegateTrait;

  private int $lineHeight;

  public function __construct(
    private readonly Communicator $communicator,
    private readonly Language     $language,
    private readonly SwimCore     $core,
    private readonly string       $requestId,
    private readonly array|null   $userPerms,
    private readonly array        $roles,
    private readonly string       $senderName,
    private readonly string       $channelId,
    private readonly string       $userId,
  )
  {
    if (isset($userPerms)) {
      $this->perm = new PermissibleBase([DefaultPermissions::ROOT_USER => true]);
      foreach ($userPerms as $userPerm)
        $this->perm->addAttachment($core, $userPerm, true);
    } else {
      $this->perm = new PermissibleBase([DefaultPermissions::ROOT_OPERATOR => true]);
    }
  }

  public function getServer(): Server
  {
    return $this->core->getServer();
  }

  public function getLanguage(): Language
  {
    return $this->language;
  }

  public function getName(): string
  {
    return $this->senderName;
  }

  public function getPermLevel(): int
  {
    if (in_array($this->communicator->getDiscordInfo()->ownerRole, $this->roles)) {
      return Rank::OWNER_RANK;
    }
    if (in_array($this->communicator->getDiscordInfo()->modRole, $this->roles)) {
      return Rank::MOD_RANK;
    }
    if (in_array($this->communicator->getDiscordInfo()->helperRole, $this->roles)) {
      return Rank::HELPER_RANK;
    }
    return Rank::DEFAULT_RANK;
  }

  public function getScreenLineHeight(): int
  {
    return $this->lineHeight ?? PHP_INT_MAX;
  }

  public function setScreenLineHeight(?int $height): void
  {
    if ($height !== null && $height < 1) {
      throw new InvalidArgumentException("Line height must be at least 1");
    }
    $this->lineHeight = $height;
  }


  public function sendMessage(Translatable|string $message): void
  {
    if ($message instanceof Translatable) {
      $message = $this->getLanguage()->translate($message);
    }
    $pk = new DiscordCommandMessagePacket;
    $pk->requestId = $this->requestId;
    $pk->commandMessage = $message;
    $this->communicator->write($pk->encodeToString());
  }

}