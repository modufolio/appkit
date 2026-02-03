<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

/**
 * Native PHP server ApplicationState implementation.
 *
 * Manages request-scoped state for PHP's built-in server with:
 * - Automatic session cookie handling (PHP manages Set-Cookie headers)
 * - Native session storage using PHP's built-in session functions
 * - PSR-7 request compatibility for session ID extraction
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class NativeApplicationState extends AbstractApplicationState
{
    /**
     * Get the session with native PHP server initialization.
     *
     * PHP's built-in server handles session cookies automatically,
     * so we just need to start the session and let PHP manage the rest.
     */
    public function getSession(): FlashBagAwareSessionInterface
    {
        if ($this->session !== null) {
            return $this->session;
        }

        // Create session handler
        $handler = new NativeFileSessionHandler(
            BASE_DIR . '/var/sessions'
        );

        // For PSR-7 requests, extract session ID from cookies
        $cookies = $this->request->getCookieParams();
        $requestSessionId = $cookies[$this->sessionCookieName] ?? null;

        // Create native storage with automatic cookie handling
        $this->sessionStorage = new NativeSessionStorage([
            'save_path' => BASE_DIR . '/var/sessions',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ], $handler);

        $this->session = new Session($this->sessionStorage);

        // Set session ID from request cookie before starting (for PSR-7 compatibility)
        if ($requestSessionId && !$this->session->isStarted()) {
            session_id($requestSessionId);
        }

        // Start session - PHP handles cookies automatically
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        return $this->session;
    }

    /**
     * Check if a new session was created.
     *
     * For native PHP server, this always returns false because PHP
     * handles cookies automatically and we don't need to manually
     * send Set-Cookie headers.
     */
    public function isNewSession(): bool
    {
        return false;
    }

    /**
     * Get the current session ID.
     *
     * For native PHP server, we use PHP's built-in session_id() function.
     */
    public function getSessionId(): ?string
    {
        if ($this->session !== null && $this->session->isStarted()) {
            return session_id() ?: null;
        }

        return null;
    }
}
