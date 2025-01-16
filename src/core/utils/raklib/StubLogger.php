<?php

namespace core\utils\raklib;

use Logger;

class StubLogger implements Logger {
    public function emergency($message) {}
    public function alert($message) {}
    public function critical($message) {}
    public function warning($message) {}
    public function debug($message) {}
    public function error($message) {}
    public function info($message) {}
    public function notice($message) {}
    public function log($level, $message) {}
    public function logException(\Throwable $e, $trace = null) {}
}
