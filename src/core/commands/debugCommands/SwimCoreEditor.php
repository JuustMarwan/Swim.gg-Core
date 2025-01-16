<?php

namespace core\commands\debugCommands;

use Closure;
use core\SwimCore;
use core\systems\player\SwimPlayer;
use core\systems\scene\Scene;
use CortexPE\Commando\BaseCommand;
use Error;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use ReflectionClass;

// an in game memory viewer and editor via server forms. If you know what's good for you, only use this in debug environments.
class SwimCoreEditor extends BaseCommand
{

  private SwimCore $core;

  public function __construct(SwimCore $core)
  {
    parent::__construct($core, "swimcore", "open the swimcore editor");
    $this->setPermission("use.staff");
    $this->core = $core;
  }

  /**
   * @inheritDoc
   */
  protected function prepare(): void
  {
    // TODO: Implement prepare() method.
  }

  public function onRun(CommandSender $sender, string $aliasUsed, array $args): void
  {
    if ($sender instanceof SwimPlayer) {
      $buttons = [];

      $form = new SimpleForm(function (SwimPlayer $swimPlayer, $data) use (&$buttons) {
        if ($data === null) {
          return;
        }

        // data in this case will be an int for the index in the buttons array that was clicked
        // Fetch the name of the scene based on the index
        $sceneNames = array_keys($buttons);
        if (isset($sceneNames[$data])) {
          $sceneName = $sceneNames[$data];
          // Now fetch the corresponding scene object using the scene name
          if (isset($buttons[$sceneName])) {
            /* @var Scene $scene */
            $scene = $buttons[$sceneName];

            // Open the inspector form for the selected scene
            $this->openSceneInspectorForm($swimPlayer, $scene);

            return;
          }

          $swimPlayer->sendMessage(TextFormat::YELLOW . "Scene not found.");
        }
      });

      $form->setTitle(TextFormat::DARK_GREEN . "Select Scene to Inspect");

      // we need to iterate all the scenes and push them back into an array that generates mapped buttons
      $ss = $this->core->getSystemManager()->getSceneSystem();
      $scenes = $ss->getScenes();
      foreach ($scenes as $name => $scene) {
        $buttons[$name] = $scene;
        $form->addButton($name);
      }

      $sender->sendForm($form);
    }
  }

  private function openSceneInspectorForm(SwimPlayer $swimPlayer, Scene $scene): void
  {
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($scene) {
      if ($data === null) {
        return;
      }

      switch ($data) {
        case 0: // Player List
          $this->openPlayerListForm($player, $scene);
          break;
        case 1: // Class Fields
          $this->openClassFieldsForm($player, $scene, $scene->getSceneName());
          break;
        case 2: // Objects
          $this->openObjectFieldsForm($player, $scene, $scene->getSceneName());
          break;
        default:
          // Invalid option
          $player->sendMessage("Invalid option selected.");
          break;
      }
    });

    $form->setTitle("Inspect Scene: " . $scene->getSceneName());
    $form->addButton("Player List");
    $form->addButton("Class Fields");
    $form->addButton("Objects");

    $swimPlayer->sendForm($form);
  }

  private function openPlayerListForm(SwimPlayer $swimPlayer, Scene $scene): void
  {
    $players = $scene->getPlayers(); // Assuming getPlayers returns an array of SwimPlayers

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($players) {
      if ($data === null) {
        return;
      }

      $playerList = array_values($players);
      if (isset($playerList[$data])) {
        $selectedPlayer = $playerList[$data];
        $this->openPlayerComponentsForm($player, $selectedPlayer);
      } else {
        $player->sendMessage("Invalid player selected.");
      }
    });

    $form->setTitle("Players in Scene");
    foreach ($players as $playerObj) {
      $form->addButton($playerObj->getName());
    }

    $swimPlayer->sendForm($form);
  }

  private function openPlayerComponentsForm(SwimPlayer $swimPlayer, SwimPlayer $targetPlayer): void
  {
    $components = $targetPlayer->getComponents(); // Assuming getComponents() returns an array of components

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($components) {
      if ($data === null) {
        return;
      }

      $componentList = array_values($components);
      if (isset($componentList[$data])) {
        $component = $componentList[$data];
        $componentName = get_class($component);

        // Now we can open a form to inspect this component
        $this->openObjectInspectorForm($player, $component, $componentName);
      } else {
        $player->sendMessage("Invalid component selected.");
      }
    });

    $form->setTitle("Components of " . $targetPlayer->getName());
    foreach ($components as $component) {
      $form->addButton(get_class($component));
    }

    $swimPlayer->sendForm($form);
  }

  private function openClassFieldsForm(SwimPlayer $swimPlayer, $object, $objectName = ''): void
  {
    $reflectionClass = new ReflectionClass($object);
    $properties = $reflectionClass->getProperties();

    $primitiveProperties = [];
    $uninitializedProperties = [];

    foreach ($properties as $property) {
      try {
        // $property->setAccessible(true); // Allow access to private/protected properties

        // Check if the property is initialized before attempting to access its value
        if ($property->isInitialized($object)) {
          $value = $property->getValue($object);

          // Check if the property is primitive (int, float, string, bool, null)
          if (is_scalar($value) || is_null($value)) {
            $primitiveProperties[] = $property;
          }
        } else {
          $uninitializedProperties[] = $property;
        }
      } catch (Error $e) {
        // Catch and handle any unexpected errors
        $uninitializedProperties[] = $property;
      }
    }

    if (empty($primitiveProperties) && empty($uninitializedProperties)) {
      $swimPlayer->sendMessage("No fields to display.");
      return;
    }

    $form = new CustomForm(function (SwimPlayer $player, array $data = null) use ($primitiveProperties, $object) {
      if ($data === null) {
        return;
      }

      // Update the fields
      foreach ($primitiveProperties as $index => $property) {
        try {
          if ($property->isInitialized($object)) {
            $oldValue = $property->getValue($object);
            $inputValue = $data[$index];
            $type = gettype($oldValue);

            // Try to parse the input value into the correct type
            $newValue = $oldValue; // Default to old value in case of errors
            switch ($type) {
              case 'integer':
                $newValue = intval($inputValue);
                break;
              case 'double':
                $newValue = floatval($inputValue);
                break;
              case 'boolean':
                // Normalize and parse boolean input
                $normalizedInput = strtolower(trim($inputValue));
                if (in_array($normalizedInput, ['true', '1', 'yes'], true)) {
                  $newValue = true;
                } elseif (in_array($normalizedInput, ['false', '0', 'no'], true)) {
                  $newValue = false;
                } else {
                  $player->sendMessage("Invalid input for boolean field: " . $property->getName());
                  continue 2; // Skip updating this field
                }
                break;
              case 'string':
                $newValue = strval($inputValue);
                break;
              case 'NULL':
                $newValue = null;
                break;
              default:
                $player->sendMessage("Unsupported type for field: " . $property->getName());
                continue 2; // Skip updating this field
            }

            $property->setValue($object, $newValue);
          }
        } catch (Error $e) {
          // Handle unexpected errors during property updates
          $player->sendMessage("Failed to update property: " . $property->getName());
        }
      }

      $player->sendMessage("Fields updated successfully.");
    });

    $title = "Edit Fields";
    if ($objectName !== '') {
      $title .= " of " . $objectName;
    }
    $form->setTitle($title);

    // Add fields to the form with labels for protection level and data type
    foreach ($primitiveProperties as $property) {
      try {
        $protection = $this->getPropertyProtectionLevel($property);
        $value = $property->getValue($object);
        $type = gettype($value);

        $stringValue = $type === 'boolean' ? ($value ? 'true' : 'false') : strval($value);
        $label = "$protection $type " . $property->getName();
        $form->addInput($label, '', $stringValue);
      } catch (Error $e) {
        // Skip safely if something goes wrong
      }
    }

    // Add uninitialized fields as labels with protection level and data type
    foreach ($uninitializedProperties as $property) {
      $protection = $this->getPropertyProtectionLevel($property);
      $type = "uninitialized";
      $form->addLabel("$protection $type " . $property->getName());
    }

    $swimPlayer->sendForm($form);
  }

  /**
   * Helper function to determine the protection level of a property.
   */
  private function getPropertyProtectionLevel(\ReflectionProperty $property): string
  {
    if ($property->isPublic()) {
      return 'public';
    } elseif ($property->isProtected()) {
      return 'protected';
    } elseif ($property->isPrivate()) {
      return 'private';
    }
    return 'unknown';
  }

  private function openObjectFieldsForm(SwimPlayer $swimPlayer, $object, $objectName = ''): void
  {
    $reflectionClass = new ReflectionClass($object);
    $properties = $reflectionClass->getProperties();

    $objectProperties = [];
    $uninitializedProperties = [];

    foreach ($properties as $property) {
      try {
        // Check if the property is initialized before attempting to access its value
        if ($property->isInitialized($object)) {
          $value = $property->getValue($object);

          // Check if the property is an object or array
          if (is_array($value) || (is_object($value) && !$value instanceof Closure)) {
            $objectProperties[] = $property;
          }
        } else {
          $uninitializedProperties[] = $property;
        }
      } catch (Error $e) {
        // Handle uninitialized property access errors or other issues
        $uninitializedProperties[] = $property;
      }
    }

    if (empty($objectProperties) && empty($uninitializedProperties)) {
      $swimPlayer->sendMessage("No object or array fields to display.");
      return;
    }

    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($objectProperties, $uninitializedProperties, $object) {
      if ($data === null) {
        return;
      }

      // Handle selected property
      if (isset($objectProperties[$data])) {
        $property = $objectProperties[$data];
        try {
          $propertyName = $property->getName();
          $propertyValue = $property->isInitialized($object) ? $property->getValue($object) : null;

          if (is_object($propertyValue)) {
            $this->openObjectInspectorForm($player, $propertyValue, $propertyName);
          } elseif (is_array($propertyValue)) {
            $this->openArrayInspectorForm($player, $propertyValue, $propertyName);
          } else {
            $player->sendMessage("Selected field is not an object or array.");
          }
        } catch (Error $e) {
          $player->sendMessage("Failed to access property: " . $property->getName());
        }
      } else {
        $player->sendMessage("Invalid option selected.");
      }
    });

    $title = "Objects";
    if ($objectName !== '') {
      $title .= " in " . $objectName;
    }
    $form->setTitle($title);

    // Add object fields to the form
    foreach ($objectProperties as $property) {
      try {
        $form->addButton($property->getName());
      } catch (Error $e) {
        $form->addButton("Unknown Property");
      }
    }

    // Add uninitialized fields as labels
    foreach ($uninitializedProperties as $property) {
      $form->addButton("Uninitialized: " . $property->getName());
    }

    $swimPlayer->sendForm($form);
  }

  private function openObjectInspectorForm(SwimPlayer $swimPlayer, $object, $objectName = ''): void
  {
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($object, $objectName) {
      if ($data === null) {
        return;
      }

      switch ($data) {
        case 0: // Class Fields
          $this->openClassFieldsForm($player, $object, $objectName);
          break;
        case 1: // Objects
          $this->openObjectFieldsForm($player, $object, $objectName);
          break;
        default:
          $player->sendMessage("Invalid option selected.");
          break;
      }
    });

    $title = "Inspect Object";
    if ($objectName !== '') {
      $title .= ": " . $objectName;
    }
    $form->setTitle($title);
    $form->addButton("Class Fields");
    $form->addButton("Objects");

    $swimPlayer->sendForm($form);
  }

  // this has a bug with inspecting the objects because of trying to index a string with a string for some reason
  private function openArrayInspectorForm(SwimPlayer $swimPlayer, array $array, $arrayName = ''): void
  {
    $form = new SimpleForm(function (SwimPlayer $player, $data) use ($array, $arrayName) {
      if ($data === null) {
        return;
      }

      $keys = array_keys($array);
      if (isset($keys[$data])) {
        $key = $keys[$data];

        try {
          // Ensure the key exists in the array
          if (array_key_exists($key, $array)) {
            $value = $array[$key];

            // Safely build the concatenated name for the element
            $safeArrayName = is_string($arrayName) ? $arrayName : strval($arrayName);
            $safeKey = is_scalar($key) ? strval($key) : -1;

            if ($safeKey != -1) {
              if (is_object($value)) {
                $this->openObjectInspectorForm($player, $value, "$safeArrayName[$safeKey]");
              } elseif (is_array($value)) {
                $this->openArrayInspectorForm($player, $value, "$safeArrayName[$safeKey]");
              } elseif (is_scalar($value) || is_null($value)) {
                $this->openPrimitiveValueEditorForm($player, $array, $key, "$safeArrayName[$safeKey]");
              } else {
                $player->sendMessage("Unsupported value type."); // maybe we should log what the unsupported value type was
              }
            } else {
              echo("$arrayName Invalid key: $key \n"); // this could blow up due to how data structures can allocate a lot of empty keys for performance ahead of time
            }
          } else {
            $player->sendMessage("Key '$key' does not exist in the array.");
          }
        } catch (Error $e) {
          $msg = "Failed to inspect array element at key '$key': " . $e->getMessage();
          $player->sendMessage($msg);
          echo($msg . "\n");
        }
      } else {
        $player->sendMessage("Invalid option selected.");
      }
    });

    // Set the form title
    $title = "Array Elements";
    if (!empty($arrayName)) {
      $title .= ": " . (is_string($arrayName) ? $arrayName : strval($arrayName));
    }
    $form->setTitle($title);

    // Add buttons for each array element
    foreach ($array as $key => $value) {
      try {
        $form->addButton(is_scalar($key) ? strval($key) : "Invalid Key");
      } catch (Error $e) {
        $form->addButton("Error Key");
      }
    }

    $swimPlayer->sendForm($form);
  }

  private function openPrimitiveValueEditorForm(SwimPlayer $swimPlayer, array &$array, $key, $fieldName = ''): void
  {
    $value = $array[$key];

    $form = new CustomForm(function (SwimPlayer $player, array $data = null) use (&$array, $key) {
      if ($data === null) {
        return;
      }

      $inputValue = $data[0];
      $oldValue = $array[$key];
      $type = gettype($oldValue);

      // Try to parse the input value into the correct type
      // $newValue = $oldValue; // Default to the old value in case of issues (unused actually)
      switch ($type) {
        case 'integer':
          $newValue = intval($inputValue);
          break;
        case 'double':
          $newValue = floatval($inputValue);
          break;
        case 'boolean':
          // Normalize and parse boolean-like input
          $normalizedInput = strtolower(trim($inputValue));
          if (in_array($normalizedInput, ['true', '1', 'yes'], true)) {
            $newValue = true;
          } elseif (in_array($normalizedInput, ['false', '0', 'no'], true)) {
            $newValue = false;
          } else {
            $player->sendMessage("Invalid input for boolean. Use 'true' or 'false'.");
            return; // Exit without updating
          }
          break;
        case 'string':
          $newValue = strval($inputValue);
          break;
        case 'NULL':
          $newValue = null;
          break;
        default:
          $player->sendMessage("Unsupported type. Cannot update this value.");
          return; // Exit without updating
      }

      $array[$key] = $newValue;
      $player->sendMessage("Value updated successfully.");
    });

    $form->setTitle("Edit Value at " . $fieldName);

    // Convert the value to a string for the input field
    $stringValue = gettype($value) === 'boolean' ? ($value ? 'true' : 'false') : strval($value);
    $form->addInput("Value", $stringValue, $stringValue);

    $swimPlayer->sendForm($form);
  }

}