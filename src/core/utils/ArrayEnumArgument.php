<?php

namespace core\utils;

use CortexPE\Commando\args\BaseArgument;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;

class ArrayEnumArgument extends BaseArgument
{

  protected array $values = [];
  private string $typeName;
  private bool $check = true;

  public function __construct(string $name, array $values, bool $optional = false, bool $check = true, string $typeName = "")
  {
    parent::__construct($name, $optional);
    $this->values = array_combine(array_map('strtolower', $values), $values);
    $this->typeName = ($typeName === "") ? ucfirst($name) : $typeName;
    $this->check = $check;
    $this->parameterData->enum = new CommandEnum($this->getTypeName(), $this->getEnumValues());
  }

  public function getNetworkType(): int
  {
    // this will be disregarded by PM anyways because this will be considered as a string enum
    return -1;
  }

  public function getTypeName(): string
  {
    return $this->typeName;
  }

  public function canParse(string $testString, CommandSender $sender): bool
  {
    return !$this->check || (bool)preg_match(
        "/^(" . implode("|", array_map("\\strtolower", $this->getEnumValues())) . ")$/iu",
        $testString
      );
  }

  public function getValue(string $string)
  {
    return $this->values[strtolower($string)] ?? $string;
  }

  public function getEnumValues(): array
  {
    return array_keys($this->values);
  }

  public function parse(string $argument, CommandSender $sender): mixed
  {
    return $this->getValue($argument);
  }

}