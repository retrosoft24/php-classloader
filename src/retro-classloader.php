<?php
/*
 * Retro ClassLoader v1.0.beta Copyright (c) 2022 Retro Soft, Inc.
 * http://retrosoft.ru/
 * Author: Dmitry Nevstruev [e-mail: braincoder@retrosoft.ru]
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Timestamp: 2022-02-07T20:10:20Z
 */

namespace RetroSoft\ClassLoader;

// Detect place in the directory structure
$depDirNames = array('classpath', 'lib', 'vendor'); // home of project dependencies
$dirBaseName = basename(__DIR__);
$homePath = null;
if(in_array($dirBaseName, $depDirNames)) { // right place for classloader.php
  $homePath = __DIR__;
}
else {
  foreach($depDirNames as $depDirName) {
    if(is_dir(__DIR__ . DIRECTORY_SEPARATOR . $depDirName)) {
      $homePath = __DIR__ . DIRECTORY_SEPARATOR . $depDirName;
      break;
    }
  }
}
if(!$homePath) {
  die("Copy " . basename(__FILE__) . " to the project or 'classpath'/'classes'/'vendor' directory.");
}

if(class_exists('Composer\Autoload\ClassLoader')) {
  die("Can not work with Composer. Do not forget comment include vendor/autoload.php");
}

$PROJECT_HOME = RetroClassLoader::slashDown($homePath);

define('RETRO_CLASSLOADER_DEBUG', false);
define('RETRO_CLASSLOADER_VERSION', '1.0.beta1');
define('RETRO_CLASSLOADER_NAME', 'Retro ClassLoader');
define('RETRO_CLASSLOADER_HOME', $PROJECT_HOME);

if(RETRO_CLASSLOADER_DEBUG) {
  ini_set('display_startup_errors', 1);
  ini_set('display_errors', 1);
  error_reporting( E_ALL);
}

$classLoader = new RetroClassLoader($PROJECT_HOME);

$configPhpFile = RetroClassLoader::slashDown($PROJECT_HOME . '/' . basename(__FILE__, '.php') . '.inc');
$environmentFile = RetroClassLoader::slashDown($PROJECT_HOME . '/' . basename(__FILE__, '.php') . '.ini');

if(!is_file($environmentFile)) {
  $classLoader->generateVariablesFile($environmentFile);
}

if(RETRO_CLASSLOADER_DEBUG || !is_file($configPhpFile)) {
  $classLoader->generateConfig($configPhpFile, $environmentFile);
}

include_once($configPhpFile);

$classLoader->initClassLoader();

// ClassLoader is working. Here is your code running...

// ====================================================================================================================
class RetroClassLoader {

  const DOT_PHP = '.php';
  const COMPOSER_JSON_FILENAME = 'composer.json';
  const MAX_LOG_SIZE = 1024 * 1024 << 1; // 0 = without checking

  protected $homePath;
  protected $variables = array();
  protected $loaderDirectory;

  public function __construct($homePath = null, $variables = null) {
    if(!isset($homePath))
      $homePath = __DIR__;
    $this->homePath = self::slashDown(realpath($homePath));
    if(isset($variables))
      $this->variables = array_merge($this->variables, $variables);

    $this->loaderDirectory = self::slashDown(realpath(__DIR__));
    if(!isset($this->variables['home_path'])) {
      $this->variables['home_path'] = $this->loaderDirectory;
    }
  }

  public function generateConfig($configFile, $inputEnvironmentFilename) {
    global $RETRO_CLASSLOADER_VARIABLES, $RETRO_CLASSLOADER_AUTOLOAD, $RETRO_CLASSLOADER_MAP;
    if(RETRO_CLASSLOADER_DEBUG) $configFile = 'php://stdout'; // debug write to console
    $this->generateAutoloadConfig($configFile, $inputEnvironmentFilename);
  }

  public function initClassLoader() {
    global $RETRO_CLASSLOADER_VARIABLES, $RETRO_CLASSLOADER_AUTOLOAD, $RETRO_CLASSLOADER_MAP;

    // --- Load class loader environment variables
    if($RETRO_CLASSLOADER_VARIABLES && is_array($RETRO_CLASSLOADER_VARIABLES)) {
      foreach ($RETRO_CLASSLOADER_VARIABLES as $key => $value) {
        // All keys with special marks: '%', '!', '~', '.'
        $mark = substr($key, 0, 1);
        $key = substr($key, 1);
        $value = $this->resolveValue($value);
        switch($mark) {
          case '%': // '%' System environment variable
            putenv($key . '=' . $value);
            break;
          case '!': // '!' define constant
            define($key, $value);
            break;
          case '~': // '~' ini_set variable
            ini_set($key, $value);
            break;
          case '.': // '.' global variable
            if (($pos = strpos($key, '.')) !== false) { // arrays supported using dot '.'
              $arrayKey = substr($key, 0, $pos);
              $key = substr($key, $pos + 1);
              $GLOBALS[$arrayKey][$key] = $value;
            }
            else {
              $GLOBALS[$key] = $value;
            }
            break;
          default:
            die('Invalid variable key: ' . $key);
        }
      }
    }

    if($RETRO_CLASSLOADER_AUTOLOAD && is_array($RETRO_CLASSLOADER_AUTOLOAD)) {
      foreach ($RETRO_CLASSLOADER_AUTOLOAD as $hashKey => $fileArray) {
        $file = $fileArray[0];
        $file = $this->resolveVars($file);
        $this->includeFileOnce($file);
      }
    }

    spl_autoload_register(array($this, 'loadClass'));
  }

  public function loadClass($className) {
    global $RETRO_CLASSLOADER_MAP;
    $this->logMsg('Loading class: ' . $className);
    // --- Class path conversion, psr-0
    $className = ltrim($className, '\\');
    $parts = explode('\\', $className);
    $classShortName = str_replace('_', '/', array_pop($parts));
    $classFileName = implode('/', $parts) . (count($parts)? '/' : '') . $classShortName;

    // Check simple class path, psr-0
    if (is_file($file = $this->homePath . '/' . $classFileName . self::DOT_PHP)) {
      $this->logMsg('Class found in classes directory: ' . $file);
      $this->includeFileOnce($file);
      return;
    }

    // --- Processing class name using map, psr-4
    if($RETRO_CLASSLOADER_MAP && is_array($RETRO_CLASSLOADER_MAP)) {
      if (isset($RETRO_CLASSLOADER_MAP[$className])) {
        $file = $RETRO_CLASSLOADER_MAP[$className][0];
        $file = $this->resolveVars($file);
        $this->logMsg('Class direct value found: ' . $file);
        $this->includeFileOnce($file);
        return;
      }
      foreach ($RETRO_CLASSLOADER_MAP as $key => $valueArray) { // example: "Html2Text\\": "test/"
        $value = $valueArray[0];
        if (strpos($className, $key) === 0) { // from root
          $this->logMsg('Class map value found: ' . $key . ' => ' . $value);
          $extraName = str_replace('\\', '/', substr($className, strlen($key)));
          if(($lastDsIdx = strrpos($extraName, '/')) !== false) {
            $extraName = substr($extraName, 0, $lastDsIdx + 1) .
                str_replace('_', '/', substr($extraName, $lastDsIdx + 1));
          }
          else {
            $extraName = str_replace('_', '/', $extraName);
          }
          $file = $value . $extraName . self::DOT_PHP;
          $file = $this->resolveVars($file);
          if (is_file($file) || strpos($file, 'zip:') === 0) {
            $this->includeFileOnce($file);
            return;
          }
          else {
            $this->logMsg('Can not found file: ' . $file, true);
          }
        }
      }
    }
  }

  protected function generateAutoloadConfig($outputPhpFile, $inputEnvironmentFilename) {
    $includes = array();
    if((is_dir($includesPath = $this->homePath . '/includes'))) {
      $includes = array_map(function ($file) {
        return '{home_path}' . substr($file, strlen($this->homePath));
      }, glob($includesPath . '/*' . self::DOT_PHP));
    }

    $composerJsonRegexp = "~" . preg_quote(self::COMPOSER_JSON_FILENAME, '~') . "$~";

    $libsAutoload = array();
    $classMap = array();
    $stack = array();
    array_push($stack, $this->homePath);
    while(($directory = array_pop($stack)) !== null) {
      $directory = self::slashDown(realpath($directory));
      if(!is_dir($directory))
        continue;
      if($dh = @opendir($directory)) {
        while (($file = readdir($dh)) !== false) {
          if ($file[0] == '.') continue;
          $filePath = $directory . '/' . $file;
          $composerJson = null;
          if (is_dir($filePath)) {
            if (is_file($jsonFilename = $filePath . '/' . self::COMPOSER_JSON_FILENAME)) {
              $composerJson = RetroClassLoader::jsonLoadFile($jsonFilename);
              if(!$composerJson)
                $this->logMsg('Can not load json: ' . $jsonFilename, true);
              $this->processComposerJsonToMap($composerJson, $filePath, null, true, $libsAutoload, $classMap);
            }
            else {
              array_push($stack, $filePath);
            }
          }
          else if (is_file($filePath) && preg_match("~\\.(zip|jar)$~", $file)) {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
              $zipList = array();
              $composerJson = false;
              for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (substr($filename, -1) == '/')
                  continue; // It is dir
                if(preg_match($composerJsonRegexp, $filename)) {
                  $composerJson = RetroClassLoader::jsonLoadContent($zip->getFromIndex($i));
                  if(!$composerJson)
                    $this->logMsg('Can not load json: ' . $filename . ' from ' .$filePath, true);
                  $this->processComposerJsonToMap($composerJson, $filePath, dirname($filename), false, $libsAutoload, $classMap);
                }
                else {
                  $zipList[] = $filename;
                }
              }
              if(!$composerJson) {
                // append dir list of classes?
              }
            }
            $zip->close();
          }


        }
        closedir($dh);
      }
    }

    uksort($classMap, function ($a, $b) {
      $diff = strlen($b) - strlen($a); // long keys firstly
      return $diff? $diff : strcmp($a, $b); // long keys firstly, a-z
    });

    $environment = array();
    if(is_file($inputEnvironmentFilename)) {
      foreach(file($inputEnvironmentFilename, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if(empty($line) || $line[0] == '#')
          continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim(trim($value), '"'), "'");
        $environment[$key] = $value;
      }
    }

    $timestamp = RetroClassLoader::getIsoTimestamp();

    $includes = array_merge($includes, $libsAutoload);

    $buf = '<' . '?php' . "\n// This file has been automatically generated, do not modify.\n// Generator: " .
        RETRO_CLASSLOADER_NAME . " v" . RETRO_CLASSLOADER_VERSION . ". Copyright (c) 2020-2099 Retro Soft, Inc. ".
        "All rights reserved.\n// Timestamp: $timestamp\n\n";
    $buf .= <<<EOT
if(!defined('RETRO_CLASSLOADER_VERSION')) {
  die("Invalid include. Use file 'classloader.php' only.\\n");
}

global \$RETRO_CLASSLOADER_VARIABLES, \$RETRO_CLASSLOADER_AUTOLOAD, \$RETRO_CLASSLOADER_MAP;

EOT;
    $buf .= "\n\n\$RETRO_CLASSLOADER_VARIABLES = array(\n";
    foreach($environment as $key => $value) {
      $buf .= "  '" . RetroClassLoader::escapeString($key) . "' => '" . RetroClassLoader::escapeString($value) .  "',\n";
    }
    $buf .= ");\n\n\$RETRO_CLASSLOADER_AUTOLOAD = array(\n";
    foreach($includes as $value) {
      $value = str_replace('{home_path}', '{$PROJECT_HOME}', $value);
      $buf .= "  '" . md5($value) . "' => array(\"" . RetroClassLoader::escapeString($value, false) . "\",),\n";
    }
    $buf .= ");\n\n\$RETRO_CLASSLOADER_MAP = array( // example: \"Html2Text\\\" => \"test/\"\n";
    foreach($classMap as $key => $value) {
      $value = str_replace('{home_path}', '{$PROJECT_HOME}', $value);
      $buf .= "  '" . RetroClassLoader::escapeString($key) . "' => array(\"" . RetroClassLoader::escapeString($value, false) . "\",),\n";
    }
    $buf .= ");\n\n";

    return file_put_contents($outputPhpFile, $buf, strpos($outputPhpFile, 'php:') === false? LOCK_EX : 0);
  }

  protected function processComposerJsonToMap($json, $storagePath, $pathInStorage, $isDir, array & $libsAutoload, array & $classMap) {
    $homeDir = $this->homePath;
    $storagePath = self::slashDown($storagePath);
    if(!empty($pathInStorage))
      $pathInStorage = self::slashDown($pathInStorage);
    $insPath = '{home_path}';
    if(isset($json['autoload'])) {
      if(isset($json['autoload']['files'])) {
        foreach($json['autoload']['files'] as $file) {
          if($isDir) {
            $file = $insPath . substr($storagePath, strlen($homeDir)) . '/' . $file;
          }
          else {
            $file = 'zip://' . $insPath . substr($storagePath, strlen($homeDir)) .  '#' . (!empty($pathInStorage)? $pathInStorage . '/' : '') . $file;
          }
          if(!in_array($file, $libsAutoload))
            array_push($libsAutoload, $file);
        }
      }
      if(isset($json['autoload']['psr-4'])) {
        foreach($json['autoload']['psr-4'] as $classKey => $classValue) {
          if(!empty($classKey) && substr($classKey, -1) != '\\') $classKey .= '\\'; // for testing simplicity
          if(!empty($classValue) && substr($classValue, -1) != '/') $classValue .= '/'; // for testing simplicity
          if($isDir) {
            $classValue = $insPath . substr($storagePath, strlen($homeDir)) . '/' . $classValue;
          }
          else {
            $classValue = 'zip://' . $insPath . substr($storagePath, strlen($homeDir)) .'#' . (!empty($pathInStorage)? $pathInStorage . '/' : '') . $classValue;
          }
          $classMap[$classKey] = $classValue;
        }
      }
    }
  }

  public function generateVariablesFile($environmentFilename) {
    // --- Environment variables
    $contents = <<<EOT
# Environment variables file for Retro ClassLoader. Every variable name have 1 symbol prefix:

# % - system environment variables;
%TELEGRAM_BOT=TelegramBotName

# ! - php define(...) constants
!DEFINED_CONST=Test of define()

# ~ - php ini_set(...) variables;
~display_errors=1

# . - php \$GLOBALS[] variables. Elements of array supported: \$GLOBALS['key1']['key2']=91 equals .key1.key2=91 
.MyVar=Text or something else...

EOT;
    file_put_contents($environmentFilename, $contents, LOCK_EX);
  }


  public function resolveVars($message) {
    if(is_array($message))
      return $message;
    return self::substitution($message, $this->variables, null);
  }

  protected static function substitution($message, array $variables, $defaultValue = null) {
    if(!empty($defaultValue)) {
      $defaultValue = self::substitution($defaultValue, $variables, null);
    }
    $message = \preg_replace_callback("~\\{([a-zA-Z0-9_\\.]{1,40})\\}~",
        function ($matches) use ($variables, $defaultValue) {
          $key = $matches[1];
          if(isset($variables[$key]))
            return $variables[$key];
          $parts = explode('.', $key, 2);
          if($parts == 2) {
            if(isset($variables[$parts[0]]) && isset($variables[$parts[0]][$parts[1]]))
              return $variables[$parts[0]][$parts[1]];
          }
          return $defaultValue;
        }, $message);
    return $message;
  }

  public static function escapeString($value, $escForPhp = true) {
    $search = array("\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a");
    $replace = array("\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z");
    $value = str_replace($search, $replace, $value);
    return $escForPhp? str_replace (array ('\\\'', '$'), array ('\'', '\\$'), $value) : $value;
  }

  protected function logMsg($message, $isError = false) {
    if(RETRO_CLASSLOADER_DEBUG || $isError) {
      $logFilename = $this->homePath . '/' . basename(__FILE__, self::DOT_PHP) . '.log';
      if(self::MAX_LOG_SIZE > 0 && is_file($logFilename) && filesize($logFilename) > self::MAX_LOG_SIZE) {
        $handle = fopen($logFilename, "r+");
        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        flock($handle, LOCK_UN);
        fclose($handle);
      }
      if (is_array($message))
        $message = implode("\n", $message);
      else if (is_object($message))
        $message = (string)$message;
      $ts = date('Y-m-d H:i:s');
      file_put_contents($logFilename, $ts . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
  }

  protected function includeFileOnce($file) {
    $this->logMsg('Loading file: ' . $file);
    return include_once($file);
  }

  protected function resolveValue($value) {
    // prepare value, process macros
    if(substr($value, 0, 6) == '@file:')
      $value = file_get_contents(substr($value, 6));
    else if(substr($value, 0, 6) == '@json:')
      $value = self::jsonLoadFile(substr($value, 6), true);
    else if(substr($value, 0, 5) == '@php:')
      $value = include_once(substr($value, 5));
    else if(substr($value, 0, 5) == '@ini:')
      $value = parse_ini_file(substr($value, 5), false);
    else if(substr($value, 0, 7) == '@lines:')
      $value = file(substr($value, 7));
    return !is_array($value)? $this->resolveVars($value) : $value;
  }

  public static final function slashDown($path) {
    return str_replace('\\', '/', $path);
  }

  public static function getTimestamp($time = null, $utc = false) {
    if(!isset($time))
      $time = time();
    return $utc? gmdate('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s', $time);
  }

  public static function getIsoTimestamp($time = null, $utc = false) {
    return str_replace(' ', 'T', self::getTimestamp($time, true)) . 'Z';
  }

  public static function jsonLoadFile($jsonFilename, $returnAssoc = true) {
    $jsonContent = file_get_contents($jsonFilename);
    return RetroClassLoader::jsonLoadContent($jsonContent, $returnAssoc);
  }

  public static function jsonLoadContent($jsonContent, $returnAssoc = true) { // json with c-style comments
    $jsonContent = preg_replace('/(((?<!http:|https:|ftp:)\/\/.*|(\/\*)([\S\s]*?)(\*\/)))/im', '', $jsonContent);
    return json_decode($jsonContent, $returnAssoc);
  }

}
