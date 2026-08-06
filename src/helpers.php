<?php

declare(strict_types=1);

use Modufolio\Appkit\Core\Env;

/*
 * Helper Functions.
 *
 * Minimal global helpers for Appkit.
 * Template functionality is now in the Template class itself.
 */
if (!function_exists('env')) {
    /**
     * Read an environment variable, with a .env file fallback.
     *
     * Called with a key this returns the value, casting the strings "true" and
     * "false" to real booleans. Called with no arguments it returns the Env
     * reader itself, for the typed accessors:
     *
     *     env('APP_ENV', 'prod')            // mixed
     *     env()->getBool('COOKIE_SECURE')   // bool
     *     env()->getInt('DB_PORT', 3306)    // int
     *     env()->getRequired('JWT_SECRET')  // string, throws when unset
     *
     * @param string|null $key     Variable name, or null for the Env reader
     * @param mixed|null  $default Value to use when the variable is not set
     */
    function env(?string $key = null, mixed $default = null): mixed
    {
        $env = Env::instance();

        return null === $key ? $env : $env->get($key, $default);
    }
}

if (!function_exists('class_basename')) {
    function class_basename(object|string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}
