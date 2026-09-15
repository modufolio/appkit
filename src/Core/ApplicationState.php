<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

/**
 * The per-request state, on PHP's native session functions.
 *
 * Holds what lives exactly as long as one request: the request itself, the
 * session, the token storage, the firewall selection cache and the
 * controller instances. Where session data is stored is the injected
 * handler's business (files under var/ by default, Redis or a database when
 * the application says so); how the cookie is issued is the injected
 * {@see SessionConfiguration}'s. PHP sends the Set-Cookie header itself.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ApplicationState extends AbstractApplicationState
{
    public function getSession(): FlashBagAwareSessionInterface
    {
        if (null !== $this->session) {
            return $this->session;
        }

        $cookies = $this->request->getCookieParams();
        $requestSessionId = $cookies[$this->sessionCookieName] ?? null;

        $this->sessionStorage = new NativeSessionStorage(
            $this->sessionConfiguration->toStorageOptions(),
            $this->sessionHandler ?? new NativeFileSessionHandler($this->varDir.'/sessions'),
        );

        $this->session = new Session($this->sessionStorage);

        // Set session ID from request cookie before starting
        if ($requestSessionId && !$this->session->isStarted()) {
            session_id($requestSessionId);
        } elseif (!$requestSessionId && PHP_SESSION_NONE === session_status() && '' !== session_id()) {
            session_id('');
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
     * Always false: PHP's session layer sends the Set-Cookie header itself,
     * so the kernel never has to.
     */
    public function isNewSession(): bool
    {
        return false;
    }

    /**
     * Get the current session ID.
     *
     * PHP's own session_id(), once the session has started.
     */
    public function getSessionId(): ?string
    {
        if (null !== $this->session && $this->session->isStarted()) {
            return session_id() ?: null;
        }

        return null;
    }
}
