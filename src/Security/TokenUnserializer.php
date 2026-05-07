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
     * @var list<class-string>
     */
    private static array $registered = [];

    public static function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!in_array($class, self::$registered, true)) {
                self::$registered[] = $class;
            }
        }
    }

    /** @internal Reset registered classes — for tests. */
    public static function reset(): void
    {
        self::$registered = [];
    }

    public static function create(string $serializedToken): mixed
    {
        try {
            $token = unserialize($serializedToken, [
                'allowed_classes' => [
                    ...self::BUILTIN_ALLOWED_CLASSES,
                    ...self::$registered,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($token === false || $token === null) {
            return null;
        }

        if (!$token instanceof TokenInterface) {
            throw new \UnexpectedValueException(sprintf(
                'Unserialized token must implement %s, got %s',
                TokenInterface::class,
                get_debug_type($token),
            ));
        }

        return $token;
    }
}
