<?php

namespace core\utils\loaders;

use Closure;
use pocketmine\data\bedrock\item\ItemDeserializer;
use pocketmine\data\bedrock\item\ItemSerializer;
use pocketmine\data\bedrock\item\ItemSerializerDeserializerRegistrar;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\utils\StringToTParser;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use ReflectionClass;
use ReflectionException;

class CustomItemLoader
{

  public static function registerCustoms(): void
  {
    // not needed yet
    // self::registerSimpleItem(ItemTypeNames::MACE, ExtraVanillaItems::MACE(), ["mace"]);
    // self::registerSimpleItem(ItemTypeNames::WIND_CHARGE, ExtraVanillaItems::WIND_CHARGE(), ["wind_charge"]);
    // self::registerSimpleItem(ItemTypeNames::SADDLE, ExtraVanillaItems::SADDLE(), ["saddle"]);
    // self::registerSimpleItem(ItemTypeNames::CAMERA, ExtraVanillaItems::CAMERA(), ["camera_item"]);
    // self::registerSimpleItem(ItemTypeNames::LODESTONE_COMPASS, ExtraVanillaItems::TRACKING_COMPASS(), ["tracking_compass", "lodestone_compass"]);
    // self::unregisterItem("minecraft:fire_charge");
    // self::registerSimpleItem(ItemTypeNames::FIRE_CHARGE, ExtraVanillaItems::FIRE_CHARGE(), ["fire_charge"]);

    self::unregisterEducationItems();
  }

  private static function replaceVanillaItems(string $methodName, Item $item): void
  {
    $upper = mb_strtoupper($methodName);
    $refl = new ReflectionClass(VanillaItems::class);
    $membersProp = $refl->getProperty("members");
    $members = $membersProp->getValue(null);
    $members[$upper] = $item;
    $membersProp->setValue(null, $members);
  }

  /**
   * @param string[] $stringToItemParserNames
   */
  private static function registerSimpleItem(string $id, Item $item, array $stringToItemParserNames): void
  {
    GlobalItemDataHandlers::getDeserializer()->map($id, fn() => clone $item);
    GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($id));

    foreach ($stringToItemParserNames as $name) {
      StringToItemParser::getInstance()->register($name, fn() => clone $item);
    }
  }

  /**
   * @phpstan-template TItem of Item
   * @phpstan-param Closure(TItem, int) : void $deserializeMeta
   * @phpstan-param Closure(TItem) : int $serializeMeta
   * @throws ReflectionException
   */
  private static function registerItemWithMeta(string $id, Item $item, array $stringToItemParserNames, Closure $deserializeMeta, Closure $serializeMeta): void
  {
    $refl = (new ReflectionClass(ItemSerializerDeserializerRegistrar::class));
    $registrar = $refl->newInstanceWithoutConstructor();
    $refl->getProperty("serializer")->setValue($registrar, GlobalItemDataHandlers::getSerializer());
    $refl->getProperty("deserializer")->setValue($registrar, GlobalItemDataHandlers::getDeserializer());

    $registrar->map1to1ItemWithMeta($id, $item, $deserializeMeta, $serializeMeta);
    foreach ($stringToItemParserNames as $name) {
      StringToItemParser::getInstance()->register($name, fn() => clone $item);
    }
  }

  /**
   * @throws ReflectionException
   */
  private static function unregisterItem(string $id): void
  {
    $deserializerRefl = (new ReflectionClass(ItemDeserializer::class))->getProperty("deserializers");
    $deserializers = $deserializerRefl->getValue(GlobalItemDataHandlers::getDeserializer());
    unset($deserializers[$id]);
    $deserializerRefl->setValue(GlobalItemDataHandlers::getDeserializer(), $deserializers);

    $item = StringToItemParser::getInstance()->parse($id);
    if ($item) {
      $serializerRefl = (new ReflectionClass(ItemSerializer::class))->getProperty("itemSerializers");
      $serializers = $serializerRefl->getValue(GlobalItemDataHandlers::getSerializer());
      unset($serializers[$item->getTypeId()]);
      $serializerRefl->setValue(GlobalItemDataHandlers::getSerializer(), $serializers);

      $reverseRefl = (new ReflectionClass(StringToItemParser::class))->getProperty("reverseMap");
      $reverseMap = $reverseRefl->getValue(StringToItemParser::getInstance());
      unset($reverseMap[$item->getStateId()]);
      $reverseRefl->setValue(StringToItemParser::getInstance(), $reverseMap);
    }

    $callbackMapRefl = (new ReflectionClass(StringToTParser::class))->getProperty("callbackMap");
    $callbackMap = $callbackMapRefl->getValue(StringToItemParser::getInstance());
    unset($callbackMap[strtolower(str_replace([" ", "minecraft:"], ["_", ""], trim($id)))]);
    $callbackMapRefl->setValue(StringToItemParser::getInstance(), $callbackMap);

    $legacyRefl = (new ReflectionClass(LegacyStringToItemParser::class))->getProperty("map");
    $legacyMap = $legacyRefl->getValue(LegacyStringToItemParser::getInstance());
    unset($legacyMap[strtolower(str_replace([" ", "minecraft:"], ["_", ""], trim($id)))]);
    $legacyRefl->setValue(LegacyStringToItemParser::getInstance(), $legacyMap);

  }

  private static function unregisterEducationItems(): void
  {
    foreach (StringToItemParser::getInstance()->getKnownAliases() as $item) {
      if (self::isEdu($item)) {
        CreativeInventory::getInstance()->remove(StringToItemParser::getInstance()->parse($item));
        self::unregisterItem("minecraft:$item");
      }
    }
  }

  private static function isEdu(string $item): bool
  {
    return
      (
      str_contains($item, "hard")
      && (str_contains($item, "glass")))
      || str_starts_with($item, "chemical_")
      || str_starts_with($item, "element_")
      || str_starts_with($item, "colored_torch")
      || in_array($item, ["material_reducer", "lab_table", "compound_creator", "blue_torch", "underwater_torch", "red_torch", "green_torch", "purple_torch"],
        true
      );
  }

}