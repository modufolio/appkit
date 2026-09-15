<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

/**
 * How the session cookie is issued and how long a session lives.
 *
 * The kernel's default reads `COOKIE_SECURE` from the environment once per
 * process (see {@see Kernel::sessionConfiguration()}); an application that
 * wants different cookie flags, another cookie name or a lifetime declares
 * this class in config/services.php:
 *
 *     $services->set(SessionConfiguration::class, fn () => new SessionConfiguration(
 *         name: 'APPSESSID',
 *         cookieSecure: true,
 *         cookieLifetime: 86_400,
 *     ));
 *
 * Where the session data is stored is a separate concern, the session
 * handler — see {@see Kernel::sessionHandler()}.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class SessionConfiguration
{
    /**
     * @param string      $name           the session cookie name
     * @param bool        $cookieSecure   send the cookie over https only
     * @param bool        $cookieHttpOnly keep the cookie away from JavaScript
     * @param string      $cookieSameSite `Lax`, `Strict` or `None`
     * @param string      $cookiePath     the cookie path
     * @param string|null $cookieDomain   the cookie domain; null for the request host
     * @param int         $cookieLifetime seconds until the cookie expires; 0 for a session cookie
     * @param int|null    $gcMaxLifetime  seconds of inactivity after which the server may
     *                                    discard the session; null keeps php.ini's value
     */
    public function __construct(
        public string $name = 'PHPSESSID',
        public bool $cookieSecure = false,
        public bool $cookieHttpOnly = true,
        public string $cookieSameSite = 'Lax',
        public string $cookiePath = '/',
        public ?string $cookieDomain = null,
        public int $cookieLifetime = 0,
        public ?int $gcMaxLifetime = null,
    ) {
        if ('' === $name) {
            throw new \InvalidArgumentException('The session cookie name must not be empty.');
        }

        if (!\in_array($cookieSameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException(sprintf('Session cookie SameSite must be "Lax", "Strict" or "None", got "%s".', $cookieSameSite));
        }

        if ('None' === $cookieSameSite && !$cookieSecure) {
            throw new \InvalidArgumentException('A SameSite=None session cookie must also be Secure, or browsers reject it.');
        }

        if ($cookieLifetime < 0 || (null !== $gcMaxLifetime && $gcMaxLifetime < 1)) {
            throw new \InvalidArgumentException('Session lifetimes must not be negative.');
        }
    }

    /**
     * The defaults, with the Secure flag taken from `COOKIE_SECURE`.
     */
    public static function fromEnvironment(): self
    {
        return new self(cookieSecure: Env::instance()->getBool('COOKIE_SECURE', false));
    }

    /**
     * The options for Symfony's NativeSessionStorage, which applies each one
     * as the matching `session.*` ini directive.
     *
     * @return array<string, int|string|bool>
     */
    public function toStorageOptions(): array
    {
        $options = [
            'name' => $this->name,
            'cookie_secure' => $this->cookieSecure,
            'cookie_httponly' => $this->cookieHttpOnly,
            'cookie_samesite' => $this->cookieSameSite,
            'cookie_path' => $this->cookiePath,
            'cookie_lifetime' => $this->cookieLifetime,
            // Reject a session id the server never issued, so a fixated id
            // is never adopted.
            'use_strict_mode' => 1,
        ];

        if (null !== $this->cookieDomain) {
            $options['cookie_domain'] = $this->cookieDomain;
        }

        if (null !== $this->gcMaxLifetime) {
            $options['gc_maxlifetime'] = $this->gcMaxLifetime;
        }

        return $options;
    }
}
