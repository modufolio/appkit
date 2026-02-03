<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Security\Token\Storage\TokenStorage;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Abstract base class for ApplicationState implementations.
 *
 * Contains shared logic for request handling, firewall resolution,
 * and request-scoped instance management.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class AbstractApplicationState implements ApplicationStateInterface
{
    protected ServerRequestInterface $request;
    protected string $baseUrl;

    protected ?FlashBagAwareSessionInterface $session = null;
    protected ?SessionStorageInterface $sessionStorage = null;
    protected ?TokenStorageInterface $tokenStorage = null;

    protected array $firewallNameCache = [];
    protected array $firewallConfig = [];

    /**
     * Session cookie name - must match your session.name php.ini setting
     */
    protected string $sessionCookieName = 'PHPSESSID';

    // Request-scoped instance cache (controllers and request-specific services)
    protected array $requestInstances = [];

    /**
     * @param ServerRequestInterface $request The current HTTP request
     * @param array $firewallConfig Optional firewall configuration
     * @param Runtime|null $runtime The runtime environment (auto-detected if null)
     */
    public function __construct(
        ServerRequestInterface $request,
        array $firewallConfig = []
    ) {
        $this->request = $request;
        $this->baseUrl = $this->calculateBaseUrl($request);
        $this->firewallConfig = $firewallConfig;
    }


    // -----------------------------------------------------------------
    // Request / Base URL
    // -----------------------------------------------------------------
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        $this->baseUrl = $this->calculateBaseUrl($request);
        $this->firewallNameCache = [];

        return $this;
    }

    protected function calculateBaseUrl(ServerRequestInterface $request): string
    {
        $uri    = $request->getUri();
        $scheme = $uri->getScheme();
        $host   = $uri->getHost();
        $port   = $uri->getPort();

        $base = $scheme . '://' . $host;

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $base .= ':' . $port;
        }

        return $base;
    }

    // -----------------------------------------------------------------
    // Session - Abstract methods to be implemented by subclasses
    // -----------------------------------------------------------------

    /**
     * Get or create the session instance.
     * Implementation is runtime-specific.
     */
    abstract public function getSession(): FlashBagAwareSessionInterface;

    public function getSessionStorage(): ?SessionStorageInterface
    {
        return $this->sessionStorage;
    }

    /**
     * Check if a new session was created.
     * Implementation is runtime-specific.
     */
    abstract public function isNewSession(): bool;

    public function getSessionCookieName(): string
    {
        return $this->sessionCookieName;
    }

    /**
     * Get the current session ID.
     * Implementation is runtime-specific.
     */
    abstract public function getSessionId(): ?string;

    public function setSession(FlashBagAwareSessionInterface $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function hasSession(): bool
    {
        return $this->session !== null;
    }

    // -----------------------------------------------------------------
    // Token storage
    // -----------------------------------------------------------------
    public function getTokenStorage(): TokenStorageInterface
    {
        return $this->tokenStorage ??= new TokenStorage();
    }

    public function setTokenStorage(TokenStorageInterface $storage): self
    {
        $this->tokenStorage = $storage;
        return $this;
    }

    public function hasTokenStorage(): bool
    {
        return $this->tokenStorage !== null;
    }

    // -----------------------------------------------------------------
    // Firewall handling
    // -----------------------------------------------------------------
    public function getFirewallName(string $path): ?string
    {
        return $this->firewallNameCache[$path] ??= $this->resolveFirewallName($path);
    }

    protected function resolveFirewallName(string $path): ?string
    {
        foreach ($this->firewallConfig as $name => $config) {
            $pattern = $config['pattern'] ?? '';

            if ($pattern && $this->matchesPattern($pattern, $path)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Matches a path against a simplified firewall pattern.
     *
     * Supported syntax:
     *  - "api:0" → matches if segment 0 == "api"
     *  - "/api"  → matches if path starts with "/api"
     *
     * No regex, no ReDoS risk, just fast string operations.
     */
    protected function matchesPattern(string $pattern, string $path): bool
    {
        // Fast check: segment-based syntax (e.g. "api:0")
        if (str_contains($pattern, ':')) {
            [$value, $pos] = explode(':', $pattern, 2);
            return $this->matchesSimplePattern($value, (int) $pos, $path);
        }

        // Otherwise treat it as "starts with"
        return $this->matchesStartsWith($pattern, $path);
    }

    /**
     * Matches a specific path segment at a given position.
     */
    protected function matchesSimplePattern(string $value, int $position, string $path): bool
    {
        // Trim slashes and split path
        $segments = explode('/', trim($path, '/'));

        return isset($segments[$position]) && $segments[$position] === $value;
    }

    /**
     * Matches if the path starts with the given pattern.
     */
    protected function matchesStartsWith(string $pattern, string $path): bool
    {
        // Normalize pattern to always start with a slash
        if (!isset($pattern[0]) || $pattern[0] !== '/') {
            $pattern = '/' . ltrim($pattern, '/');
        }

        return str_starts_with($path, $pattern);
    }

    public function setFirewallConfig(array $config): self
    {
        $this->firewallConfig = $config;
        $this->firewallNameCache = [];
        return $this;
    }

    public function getFirewallConfig(): array
    {
        return $this->firewallConfig;
    }

    public function getCurrentFirewallName(): ?string
    {
        return $this->getFirewallName($this->request->getUri()->getPath());
    }

    // -----------------------------------------------------------------
    // Request-scoped instance cache
    // -----------------------------------------------------------------
    public function hasRequestInstance(string $id): bool
    {
        return isset($this->requestInstances[$id]);
    }

    public function getRequestInstance(string $id): mixed
    {
        return $this->requestInstances[$id] ?? null;
    }

    public function setRequestInstance(string $id, mixed $instance): self
    {
        $this->requestInstances[$id] = $instance;
        return $this;
    }

    public function clearRequestInstances(): self
    {
        $this->requestInstances = [];
        return $this;
    }

    // -----------------------------------------------------------------
    // Reset (Memory Leak Prevention)
    // -----------------------------------------------------------------

    /**
     * Reset the application state to prevent memory leaks.
     * Can be extended by subclasses for runtime-specific cleanup.
     */
    public function reset(): void
    {
        // Save and close session if it's active
        if ($this->session !== null && $this->session->isStarted()) {
            $this->session->save();
        }
        $this->session = null;

        // Reset session storage
        $this->sessionStorage = null;

        // Clear token storage to break circular references
        if ($this->tokenStorage !== null) {
            $this->tokenStorage->setToken(null);
        }
        $this->tokenStorage = null;

        // Clear request-scoped instances
        $this->requestInstances = [];

        // Clear firewall cache
        $this->firewallNameCache = [];
    }
}
