<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\Token\Storage\TokenStorage;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;
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
    protected string $baseDir;
    protected string $varDir;

    protected ?FlashBagAwareSessionInterface $session = null;
    protected ?SessionStorageInterface $sessionStorage = null;
    protected ?TokenStorageInterface $tokenStorage = null;

    /** @var array<string, string|null> keyed by path */
    protected array $firewallNameCache = [];

    /** @var array<string, string|null> keyed by method+host+ip+path */
    protected array $firewallRequestCache = [];
    /** @var array<string, array<string, mixed>> */
    protected array $firewallConfig = [];

    /**
     * Session cookie name - must match your session.name php.ini setting.
     */
    protected string $sessionCookieName = 'PHPSESSID';

    // Request-scoped instance cache (controllers and request-specific services)
    /** @var array<string, object> */
    protected array $requestInstances = [];

    /**
     * @param ServerRequestInterface              $request        The current HTTP request
     * @param string                              $baseDir        Application base directory
     * @param array<string, array<string, mixed>> $firewallConfig Optional firewall configuration
     * @param string|null                         $varDir         Writable runtime directory (sessions, caches).
     *                                                            Defaults to $baseDir/var.
     */
    public function __construct(
        ServerRequestInterface $request,
        string $baseDir,
        array $firewallConfig = [],
        ?string $varDir = null,
    ) {
        $this->request = $request;
        $this->baseDir = $baseDir;
        $this->varDir = $varDir ?? $baseDir.'/var';
        $this->baseUrl = $this->calculateBaseUrl($request);
        $this->firewallConfig = $firewallConfig;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getVarDir(): string
    {
        return $this->varDir;
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
        $this->firewallRequestCache = [];

        return $this;
    }

    /**
     * scheme://host[:port] of the request, or "" when the URI has no
     * scheme/host.
     *
     * The host comes straight from the request. That is only safe because the
     * kernel validates it against the trusted-hosts allowlist before this
     * state is constructed (Kernel::createState()); configure
     * `trusted_hosts` in production so a spoofed Host header cannot become
     * the base of every absolute URL the response generates.
     */
    protected function calculateBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();

        // If scheme or host is empty (e.g., in test environments with relative URIs),
        // return empty string to use path-only URLs
        if (empty($scheme) || empty($host)) {
            return '';
        }

        $base = $scheme.'://'.$host;

        if (null !== $port && (('http' === $scheme && 80 !== $port) || ('https' === $scheme && 443 !== $port))) {
            $base .= ':'.$port;
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
        return null !== $this->session;
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
        return null !== $this->tokenStorage;
    }

    // -----------------------------------------------------------------
    // Firewall handling
    // -----------------------------------------------------------------

    /**
     * Resolve the firewall for a request, honouring the full set of firewall
     * restrictions — pattern, methods, host and ips — the way Symfony does.
     *
     * A firewall handles the request only if EVERY restriction it declares
     * matches; the first firewall (in declaration order) that matches wins.
     * This is what makes a `methods`-scoped firewall safe: a firewall meant
     * for GET only is skipped for a POST, which then falls through to the next
     * matching firewall (typically the authenticated one).
     *
     * @see https://symfony.com/doc/current/security/firewall_restriction.html
     */
    public function getFirewallNameForRequest(ServerRequestInterface $request): ?string
    {
        $path = RequestMatcher::securityPath($request->getUri());
        $method = strtoupper($request->getMethod());
        $host = $request->getUri()->getHost();
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        $cacheKey = $method."\0".$host."\0".($ip ?? '')."\0".$path;

        return $this->firewallRequestCache[$cacheKey]
            ??= $this->resolveFirewallNameForRequest($path, $method, $host, $ip);
    }

    /**
     * Pattern-only firewall resolution.
     *
     * Kept for callers that only have a path (URL generation, tooling) and for
     * backward compatibility. Security-critical selection goes through
     * getFirewallNameForRequest(), which also honours methods/host/ips.
     */
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

    protected function resolveFirewallNameForRequest(string $path, string $method, string $host, ?string $ip): ?string
    {
        foreach ($this->firewallConfig as $name => $config) {
            $pattern = $config['pattern'] ?? '';

            if (!$pattern || !$this->matchesPattern($pattern, $path)) {
                continue;
            }

            if (!$this->matchesFirewallRestrictions($config, $method, $host, $ip)) {
                continue;
            }

            return $name;
        }

        return null;
    }

    /**
     * Whether a firewall's methods/host/ips restrictions all match the request.
     * A restriction that is not declared imposes no constraint.
     *
     * @param array<string, mixed> $config
     */
    protected function matchesFirewallRestrictions(array $config, string $method, string $host, ?string $ip): bool
    {
        $methods = $config['methods'] ?? [];
        if (is_array($methods) && [] !== $methods
            && !in_array($method, array_map('strtoupper', $methods), true)) {
            return false;
        }

        $allowedHost = $config['host'] ?? null;
        if (is_string($allowedHost) && '' !== $allowedHost
            && 0 !== strcasecmp($allowedHost, $host)) {
            return false;
        }

        $ips = $config['ips'] ?? [];
        if (is_array($ips) && [] !== $ips
            && (null === $ip || !IpUtils::checkIp($ip, $ips))) {
            return false;
        }

        return true;
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
        return RequestMatcher::matches($pattern, $path);
    }

    /**
     * Matches a specific path segment at a given position.
     */
    protected function matchesSimplePattern(string $value, int $position, string $path): bool
    {
        return RequestMatcher::matchesSegment($value, $position, $path);
    }

    /**
     * Matches if the path starts with the given pattern, on whole path segments.
     *
     * A pattern of "/admin" matches "/admin" and "/admin/users" but NOT
     * "/administrator" — the same segment-boundary rule the access-control
     * matcher uses (both delegate to RequestMatcher), so firewall and
     * access-control coverage stay consistent. The "/" pattern still matches
     * every path (catch-all firewall).
     */
    protected function matchesStartsWith(string $pattern, string $path): bool
    {
        return RequestMatcher::matchesPrefix($pattern, $path);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    public function setFirewallConfig(array $config): self
    {
        $this->firewallConfig = $config;
        $this->firewallNameCache = [];
        $this->firewallRequestCache = [];

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
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
        if (null !== $this->session && $this->session->isStarted()) {
            $this->session->save();
        }
        $this->session = null;

        // Reset session storage
        $this->sessionStorage = null;

        // Clear token storage to break circular references
        if (null !== $this->tokenStorage) {
            $this->tokenStorage->setToken(null);
        }
        $this->tokenStorage = null;

        // Clear request-scoped instances
        $this->requestInstances = [];

        // Clear firewall cache
        $this->firewallNameCache = [];
        $this->firewallRequestCache = [];
    }
}
