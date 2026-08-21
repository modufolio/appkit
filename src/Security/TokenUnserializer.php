<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\OAuthToken;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class TokenUnserializer
{
    /**
     * Built-in classes always allowed during token deserialization.
     *
     * Security: we MUST NOT pass `allowed_classes => true` — that would let
     * any autoloadable class be constructed from session-stored data, turning
     * any class with dangerous `__destruct` / `__wakeup` magic methods into a
     * remote-code-execution gadget. (OWASP A08:2021)
     *
     * @var list<class-string>
     */
    private const BUILTIN_ALLOWED_CLASSES = [
        ApiKeyToken::class,
        JwtToken::class,
        OAuthToken::class,
        RememberMeToken::class,
        SwitchUserToken::class,
        TwoFactorToken::class,
        UsernamePasswordToken::class,
        InMemoryUser::class,
    ];

    /**
     * Application-supplied classes that may appear inside a serialized token
     * (typically the User entity referenced by the token).
     *
     * Register these once during boot:
     *
     *     TokenUnserializer::register(\App\Entity\User::class);
     *
     * After registration is complete, call freeze() to prevent further
     * mutations — any later register() call will throw. This narrows the
     * gadget surface: post-boot code (including injected/loaded code paths)
     * cannot widen the unserialize whitelist.
     *
     * @var list<class-string>
     */
    private static array $registered = [];

    private static bool $frozen = false;

    /**
     * Sentinel code marking an ErrorException this class raised from its own
     * unserialize() call, so the catch below can tell it apart from an
     * ErrorException bubbling up out of a token's __unserialize().
     */
    private const UNSERIALIZE_ERROR_CODE = 0x544F4B;

    /**
     * @param class-string ...$classes
     */
    public static function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (in_array($class, self::$registered, true)) {
                // Idempotent: re-registering the same class post-boot is safe.
                continue;
            }
            if (self::$frozen) {
                throw new \LogicException(sprintf('TokenUnserializer is frozen; cannot register "%s" after boot. Register all classes before calling Kernel::boot().', $class));
            }
            self::$registered[] = $class;
        }
    }

    /**
     * Lock the registered class list. Subsequent register() calls throw.
     * The framework calls this from Kernel::boot() after consumer registration.
     */
    public static function freeze(): void
    {
        self::$frozen = true;
    }

    /** @internal Reset registered classes — for tests. */
    public static function reset(): void
    {
        self::$registered = [];
        self::$frozen = false;
    }

    public static function create(#[\SensitiveParameter] string $serializedToken): ?TokenInterface
    {
        // Malformed input is expected here, not exceptional: the serialized
        // token comes from a session record or a cookie, both attacker-
        // controllable. unserialize() signals a truncated or corrupted payload
        // with an E_WARNING and a false return rather than an exception, so
        // a plain catch never sees it, and the warning reaches the error log
        // (and the test output) for a condition that is fully handled.
        //
        // Only diagnostics raised by our own unserialize() call are converted
        // — those carry this file as their origin. A warning raised inside a
        // token's or user's __wakeup()/__unserialize() belongs to that class's
        // file and is handed to the previous handler untouched, so real
        // problems in those methods stay loud.
        $previousHandler = set_error_handler(
            static function (int $type, string $message, string $file, int $line, array $context = []) use (&$previousHandler): bool {
                if (__FILE__ === $file && !in_array($type, [\E_DEPRECATED, \E_USER_DEPRECATED], true)) {
                    throw new \ErrorException($message, self::UNSERIALIZE_ERROR_CODE, $type, $file, $line);
                }

                return $previousHandler ? (bool) $previousHandler($type, $message, $file, $line, $context) : false;
            }
        );

        try {
            $token = unserialize($serializedToken, [
                'allowed_classes' => [
                    ...self::BUILTIN_ALLOWED_CLASSES,
                    ...self::$registered,
                ],
            ]);
        } catch (\ErrorException $e) {
            if (self::UNSERIALIZE_ERROR_CODE !== $e->getCode()) {
                throw $e;
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        if (false === $token || null === $token) {
            return null;
        }

        if (!$token instanceof TokenInterface) {
            throw new \UnexpectedValueException(sprintf('Unserialized token must implement %s, got %s', TokenInterface::class, get_debug_type($token)));
        }

        return $token;
    }
}
