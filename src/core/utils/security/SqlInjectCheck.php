<?php

namespace core\utils\security;

class SqlInjectCheck
{

  public static function isSqlInjectionAttempt(string $input): bool
  {
    // Common SQL injection keywords and patterns
    $sqlInjectionPatterns = [
      '1=1',
      '1=0',
      '1 OR 1=1',
      '1 OR 1=0',
      '1; DROP ',
      '--',
      '/*',
      '*/',
      'UNION ALL',
      'SELECT',
      'INSERT',
      'UPDATE',
      'DELETE',
      'CHAR(',
      'CONVERT(',
      'CAST(',
    ];

    // Convert input to lowercase for case-insensitive matching
    $input = strtoupper($input);

    // Check for SQL injection patterns
    foreach ($sqlInjectionPatterns as $pattern) {
      if (str_contains($input, $pattern)) {
        return true; // Potential SQL injection attempt detected
      }
    }

    return false; // No SQL injection patterns found
  }

  public static function isValidUuidOrHex(string $input): bool
  {
    if (strlen($input) > 127) {
      return false;
    }
    // Validate input as UUID or hex string
    $uuidRegex = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';
    $hexRegex = '/^[\da-fA-F]+$/';
    return preg_match($uuidRegex, $input) || preg_match($hexRegex, $input);
  }

  public static function isBase64(string $input): bool
  {
    return (bool)preg_match('/^[a-zA-Z\d\/\r\n+]*={0,2}$/', $input);
  }

}