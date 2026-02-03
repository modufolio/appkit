<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Interface for managing request-scoped application state.
 *
 * Implementations handle runtime-specific behaviors (RoadRunner vs built-in PHP server)
 * for session management, token storage, and request-scoped services.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ApplicationStateInterface extends ResetInterface
{

    /**
     * Get the current HTTP request.
     */
    public function getRequest(): ServerRequestInterface;

    /**
     * Get the base URL for the application.
     */
    public function getBaseUrl(): string;

    /**
     * Replace the current request with a new one.
     *
     * Recalculates base URL and clears firewall cache.
     */
    public function setRequest(ServerRequestInterface $request): self;

    /**
     * Get the current session.
     */
    public function getSession(): FlashBagAwareSessionInterface;

    /**
     * Get the session storage for advanced operations.
     */
    public function getSessionStorage(): ?SessionStorageInterface;

    /**
     * Check if a new session was created (requires Set-Cookie header).
     */
    public function isNewSession(): bool;

    /**
     * Get the session cookie name.
     */
    public function getSessionCookieName(): string;

    /**
     * Get the current session ID (for Set-Cookie header).
     */
    public function getSessionId(): ?string;

    /**
     * Set the session instance.
     */
    public function setSession(FlashBagAwareSessionInterface $session): self;

    /**
     * Check if a session has been initialized.
     */
    public function hasSession(): bool;

    /**
     * Get the token storage for authentication.
     */
    public function getTokenStorage(): TokenStorageInterface;

    /**
     * Set the token storage instance.
     */
    public function setTokenStorage(TokenStorageInterface $storage): self;

    /**
     * Check if token storage has been initialized.
     */
    public function hasTokenStorage(): bool;

    /**
     * Get the firewall name for a given path.
     */
    public function getFirewallName(string $path): ?string;

    /**
     * Set the firewall configuration.
     */
    public function setFirewallConfig(array $config): self;

    /**
     * Get the firewall configuration.
     */
    public function getFirewallConfig(): array;

    /**
     * Get the firewall name for the current request.
     */
    public function getCurrentFirewallName(): ?string;

    /**
     * Check if a request-scoped instance exists.
     */
    public function hasRequestInstance(string $id): bool;

    /**
     * Get a request-scoped instance.
     */
    public function getRequestInstance(string $id): mixed;

    /**
     * Store a request-scoped instance.
     */
    public function setRequestInstance(string $id, mixed $instance): self;

    /**
     * Clear all request-scoped instances.
     */
    public function clearRequestInstances(): self;
}
