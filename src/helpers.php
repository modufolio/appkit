<?php

declare(strict_types = 1);

/**
 * Helper Functions
 *
 * Minimal global helpers for Appkit.
 * Template functionality is now in the Template class itself.
 */

if (!function_exists('env')) {
    /**
     * Get environment variable with .env file fallback.
     *
     * @param string $key Environment variable name
     * @param mixed|null $default Default value if not found
     * @return mixed
     */
    function env(string $key, mixed $default = null)
    {
        static $loaded = [];

        if (empty($loaded) && file_exists(BASE_DIR . '/.env')) {
            $parsed = parse_ini_file(BASE_DIR . '/.env', false, INI_SCANNER_RAW);
            if ($parsed !== false) {
                $loaded = array_map(function ($value) {
                    $value = trim($value, '"');
                    return in_array($value, ['true', 'false']) ? ($value === 'true') : $value;
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
