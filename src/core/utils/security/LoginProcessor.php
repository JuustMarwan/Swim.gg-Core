<?php

namespace core\utils\security;

use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\InputMode;

final class TouchMode
{
  const DPAD = 5;
  const JOYSTICK = 6;
}

// in swimgg private, this class runs a ton of log in checks for stuff like toolbax and proxy cheats, right now we are just using this as a pure data class

class LoginProcessor
{

  private static array $titleIdToNameMap = array(
    1739947436 => "Android",
    1810924247 => "iOS",
    1944307183 => "FireOS",
    896928775 => "Windows",
    2044456598 => "Playstation",
    2047319603 => "Nintendo",
    1828326430 => "Xbox",
  );

  private static array $titleIdToDeviceOSMap = array(
    1739947436 => 1,
    1810924247 => 2,
    1944307183 => 4,
    896928775 => 7,
    2044456598 => 11,
    2047319603 => 12,
    1828326430 => 13,
  );

  private static array $defaultInputModeToDeviceOSMap = array(
    1 => [3, 7, 8, 15],
    2 => [1, 2, 4, 14],
    3 => [10, 11, 12, 13],
    4 => [5, 6]
  );

  // used in anti cheat data for setting the player's device OS
  public static array $platformMap = array(
    DeviceOS::ANDROID => "Android",
    DeviceOS::IOS => "iOS",
    DeviceOS::OSX => "macOS",
    DeviceOS::AMAZON => "FireOS",
    DeviceOS::GEAR_VR => "GearVR",
    DeviceOS::HOLOLENS => "HoloLens",
    DeviceOS::WINDOWS_10 => "Windows",
    DeviceOS::WIN32 => "Windows",
    DeviceOS::DEDICATED => "Dedicated",
    DeviceOS::TVOS => "tvOS",
    DeviceOS::PLAYSTATION => "PlayStation",
    DeviceOS::NINTENDO => "Nintendo",
    DeviceOS::XBOX => "Xbox",
    DeviceOS::WINDOWS_PHONE => "WinPhone",
    15 => "Linux"
  );

  // used when logging what input mode the player is using
  public static array $inputModeMap = array(
    InputMode::MOUSE_KEYBOARD => "Keyboard",
    InputMode::TOUCHSCREEN => "Touch",
    InputMode::GAME_PAD => "Controller",
    InputMode::MOTION_CONTROLLER => "MotionController",
    TouchMode::DPAD => "TouchDpad",
    TouchMode::JOYSTICK => "TouchJoystick",
  );

}