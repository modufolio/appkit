<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

/**
 * Error-output hardening, modelled on Symfony's Debug/ErrorHandler split.
 *
 * The problem it solves: with `display_errors` on, the first warning PHP
 * prints becomes the first output byte, which commits the response headers
 * with the default 200 status. The real response — often a 500 built by the
 * exception handler — then loses its status line and every header to
 * "headers already sent". PHP must therefore never print errors into the
 * response stream; error rendering belongs to the framework.
 *
 * `enable()` (dev): warnings and notices are thrown as \ErrorException, so
 * they route through the kernel's catch and ExceptionHandler as a clean 500 —
 * fail loud, through the front door. `display_errors` is switched off for web
 * SAPIs; deprecations and @-silenced errors are left to PHP's logger.
 *
 * `harden()` (prod): `display_errors` is switched off for web SAPIs as a
 * defence in depth — the ini should already say so, but a dev php.ini serving
 * a prod app is exactly the mixed configuration that leaks.
 *
 * The kernel wires this in boot() by environment; test is left untouched so
 * PHPUnit keeps its own error handling.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class Debug
{
    /**
     * Dev mode: throw warnings/notices as \ErrorException and keep PHP's own
     * error output away from web responses.
     */
    public static function enable(): void
    {
        error_reporting(\E_ALL);
        self::harden();

        set_error_handler(static function (int $type, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $type)) {
                return false; // @-silenced — leave to PHP
            }

            if ($type & (\E_DEPRECATED | \E_USER_DEPRECATED)) {
                return false; // deprecations are logged, never thrown
            }

            throw new \ErrorException($message, 0, $type, $file, $line);
        });
    }

    /**
     * Prod mode: PHP never prints errors into the response stream. CLI SAPIs
     * (including RoadRunner workers) are left alone — there stderr is the
     * right place for errors.
     */
    public static function harden(): void
    {
        if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            ini_set('display_errors', '0');
        }
    }
}
