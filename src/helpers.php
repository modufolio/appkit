<?php

declare(strict_types=1);

/**
 * Helper Functions.
 *
 * Minimal global helpers for Appkit.
 * Template functionality is now in the Template class itself.
 */
if (!function_exists('env')) {
    /**
     * Get environment variable with .env file fallback.
     *
     * @param string     $key     Environment variable name
     * @param mixed|null $default Default value if not found
     */
    function env(string $key, mixed $default = null)
    {
        static $loaded = [];

        // BASE_DIR is defined by the application's bootstrap. Without this
        // guard, calling env() from a script that does not define it (a
        // one-off CLI script, a worker bootstrap, a test harness) is a fatal
        // error rather than a lookup that falls back to the real environment.
        $baseDir = defined('BASE_DIR') ? constant('BASE_DIR') : null;

        if (empty($loaded) && is_string($baseDir) && file_exists($baseDir.'/.env')) {
            $parsed = parse_ini_file($baseDir.'/.env', false, INI_SCANNER_RAW);
            if (false !== $parsed) {
                $loaded = array_map(function ($value) {
                    $value = trim($value, '"');

                    return in_array($value, ['true', 'false']) ? ('true' === $value) : $value;
                }, $parsed);
            }
        }

        return $_ENV[$key] ?? $_SERVER[$key] ?? $loaded[$key] ?? $default;
    }
}

if (!function_exists('class_basename')) {
    function class_basename(object|string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}
