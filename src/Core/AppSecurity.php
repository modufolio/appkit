<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Toolkit\A;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Security trait for authentication and authorization functionality.
 *
 * This trait provides authentication flow and authorization enforcement for the App class.
 * It handles:
 * - Authentication flow (session restoration, authenticators, entry points)
 * - Authorization (access control enforcement)
 * - Logout functionality
 *
 * @method \Modufolio\Appkit\Security\User\UserProviderInterface userProvider()
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppSecurity
{
    // ============================================================================
    // AUTHENTICATION FLOW
    // ============================================================================

    /**
     * Handle authentication for the current request.
     * Manages firewall configuration, session restoration, and authenticator execution.
     *
     * @throws \ReflectionException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function handleAuthentication(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $firewallName = $this->getFirewallName($path);

        if ($firewallName === null) {
            return $this->controllerResolver($request);
        }

        $config = $this->getFirewallConfig($firewallName);
        $stateless = $config['stateless'] ?? false;

        if (($config['security'] ?? true) === false) {
            return $this->controllerResolver($request);
        }

        if ($this->isLogoutRequest($request, $config)) {
            return $this->logout($firewallName);
        }

        if ($token = $this->tryRestoreSessionToken($firewallName, $stateless)) {
            $token = $this->refreshUser($token);
            if ($token === null) {
                return $this->logout($firewallName);
            }
            $this->tokenStorage()->setToken($token);
            return $this->controllerResolver($request);
        }

        if ($this->isEntryPointPage($request, $config)) {
            return $this->controllerResolver($request);
        }

        $result = $this->tryAuthenticators($request, $config, $firewallName, $stateless);

        // Handle ResponseInterface (e.g., 2FA redirect)
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        // Handle TokenInterface (successful authentication)
        if ($result instanceof TokenInterface) {
            $this->tokenStorage()->setToken($result);
            if (!$stateless) {
                $this->session()->set('_security_' . $firewallName, serialize($result));
            }
            return $this->controllerResolver($request);
        }

        return $this->handleEntryPointRedirect($config, $stateless);
    }

    /**
     * Attempt to restore authentication token from session.
     */
    private function tryRestoreSessionToken(string $firewallName, bool $stateless): ?TokenInterface
    {
        if ($stateless) {
            return null;
        }

        $sessionKey = '_security_' . $firewallName;
        if (!$this->session()->has($sessionKey)) {
            return null;
        }

        $serializedToken = $this->session()->get($sessionKey);
        $token = TokenUnserializer::create($serializedToken);

        return $token instanceof TokenInterface && $token->getUser()
            ? $token
            : null;
    }

    /**
     * Refresh user data from the user provider.
     */
    private function refreshUser(TokenInterface $token): ?TokenInterface
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }
        try {
            $refreshedUser = $this->userProvider()->refreshUser($user);
            $newToken = clone $token;
            $newToken->setUser($refreshedUser);
            return $newToken;
        } catch (UserNotFoundException | UnsupportedUserException) {
            return null;
        }
    }

    /**
     * Check if the current request is a logout request.
     */
    private function isLogoutRequest(ServerRequestInterface $request, array $config): bool
    {
        $logoutPath = A::get($config, 'logout.path');
        return $logoutPath && $request->getMethod() === 'GET' && $request->getUri()->getPath() === $logoutPath;
    }

    /**
     * Check if the current request is for an entry point page (login, 2FA).
     */
    private function isEntryPointPage(ServerRequestInterface $request, array $config): bool
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Allow entry point (login page)
        if (isset($config['entry_point']) &&
            $method === 'GET' &&
            $path === $config['entry_point']) {
            return true;
        }

        // Allow 2FA page (GET and POST) when there's a pending 2FA token
        if (($method === 'GET' || $method === 'POST') &&
            $path === '/2fa' &&
            $this->session()->has('_2fa_token')) {
            return true;
        }

        // Allow 2FA cancel route
        if ($path === '/2fa/cancel' && $this->session()->has('_2fa_token')) {
            return true;
        }

        return false;
    }

    /**
     * Try each configured authenticator until one succeeds.
     * @throws \Exception
     */
    private function tryAuthenticators(
        ServerRequestInterface $request,
        array $config,
        string $firewallName,
        bool $stateless
    ): TokenInterface|ResponseInterface|null {
        $authenticators = array_intersect_key($this->authenticators(), array_flip($config['authenticators'] ?? []));

        foreach ($authenticators as $name => $authenticatorFactory) {
            $authenticator = $authenticatorFactory($this);
            $supports = $authenticator->supports($request);

            if ($supports) {
                try {
                    $user = $authenticator->authenticate($request);
                    $token = $authenticator->createToken($user, $firewallName);
                    return $token;
                } catch (AuthenticationException $e) {
                    if (!$stateless && isset($config['entry_point'])) {
                        // If 2FA is required, create partial auth token and redirect to /2fa
                        if (isset($e->requires2FA) && $e->requires2FA === true && isset($e->user)) {
                            $twoFactorToken = new TwoFactorToken($e->user, $firewallName, $e->user->getRoles());

                            // Store partial token in session
                            $this->session()->set('_2fa_token', serialize($twoFactorToken));

                            // Set flash message for 2FA screen
                            $this->session()->getFlashBag()->add('info', '2FA code required');

                            // Return the authenticator's unauthorized response (redirect to /2fa)
                            return $authenticator->unauthorizedResponse($request, $e);
                        }

                        // Regular auth failure - show error
                        $this->session()->getFlashBag()->add('error', $e->getMessage());
                        return null; // fall through to redirect
                    }
                    throw $e;
                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Handle redirect to entry point (login page) when authentication fails.
     */
    private function handleEntryPointRedirect(array $config, bool $stateless): ResponseInterface
    {
        if ($stateless || !isset($config['entry_point'])) {
            return Response::unauthorized();
        }

        return Response::redirect($this->url($config['entry_point']));
    }

    /**
     * Log out the current user and redirect to target path.
     */
    public function logout(string $firewallName, ?string $path = null): ResponseInterface
    {
        $config = $this->getFirewallConfig($firewallName);
        $target = A::get($config, 'logout.target', $path ?? '/');

        // Clear authentication data
        $sessionKey = '_security_' . $firewallName;
        $this->session()->remove($sessionKey);
        $this->tokenStorage()->setToken(null);

        // Invalidate session if not stateless
        if (!(($config['stateless'] ?? false))) {
            $this->session()->invalidate();
        }

        return Response::redirect($target);
    }

    /**
     * Generate CSRF token for logout
     * Creates a NEW random token each time and stores it in session
     *
     * @param string $firewallName The firewall name to generate the token for
     * @return string The generated token (32 hex characters)
     * @throws \Exception
     */
    public function generateCsrfToken(string $firewallName): string
    {
        // Always generate a new 16 random bytes = 32 hex characters token
        $token = bin2hex(random_bytes(16));

        // Store in session with key _csrf/logout_{firewallName}
        $this->session()->set('_csrf/logout_' . $firewallName, $token);

        return $token;
    }

    // ============================================================================
    // AUTHORIZATION
    // ============================================================================

    /**
     * Enforces global access control rules.
     * @throws AuthenticationException
     */
    private function enforceAccessControl(ServerRequestInterface $request): void
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        foreach ($this->accessControlRules ?? [] as $rule) {
            if (!$this->matchesAccessControlPattern($rule['path'] ?? '/', $path)) {
                continue;
            }

            if (!empty($rule['methods']) && !in_array($method, $rule['methods'], true)) {
                throw new AuthenticationException('Method not allowed for this path: ' . $path);
            }

            if (isset($rule['requires_channel']) && $rule['requires_channel'] === 'https' && $request->getUri()->getScheme() !== 'https') {
                throw new AuthenticationException('HTTPS required for this path: ' . $path);
            }

            if (!empty($rule['ips'])) {
                $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

                if (!IpUtils::checkIp($clientIp, $rule['ips'])) {
                    throw new AuthenticationException('Access denied due to IP restriction for path: ' . $path);
                }
            }

            if (!empty($rule['roles'])) {
                $token = $this->tokenStorage()->getToken();
                if ($token === null) {
                    throw new AuthenticationException('Authentication required for path: ' . $path);
                }
                $user = $token->getUser();
                if (!$user instanceof UserInterface) {
                    throw new AuthenticationException('Invalid user for path: ' . $path);
                }
                $userRoles = $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();
                $hasRole = false;
                foreach ($rule['roles'] as $requiredRole) {
                    if (in_array($requiredRole, $userRoles, true)) {
                        $hasRole = true;
                        break;
                    }
                }
                if (!$hasRole) {
                    throw new AuthenticationException('Insufficient roles for path: ' . $path);
                }
            }

            return; // Rule matched and passed
        }
    }

    /**
     * Match path against access control pattern.
     *
     * Supported syntax:
     *  - "api:0" → matches if segment 0 == "api"
     *  - "/api"  → matches if path starts with "/api"
     */
    private function matchesAccessControlPattern(string $pattern, string $path): bool
    {
        // Segment-based syntax (e.g. "api:0")
        if (str_contains($pattern, ':')) {
            [$value, $pos] = explode(':', $pattern, 2);
            $segments = explode('/', trim($path, '/'));
            return isset($segments[(int) $pos]) && $segments[(int) $pos] === $value;
        }

        // Prefix matching (e.g. "/api")
        if (!isset($pattern[0]) || $pattern[0] !== '/') {
            $pattern = '/' . ltrim($pattern, '/');
        }
        return str_starts_with($path, $pattern);
    }

    /**
     * Enforces access control based on #[IsGranted] roles in route defaults.
     * @throws AuthenticationException
     */
    private function enforceAttributeAccessControl(array $parameters): void
    {
        $requiredRoles = $parameters['_is_granted_roles'] ?? [];
        if (empty($requiredRoles)) {
            return;
        }

        $token = $this->tokenStorage()->getToken();
        if ($token === null || !$token->getUser()) {
            throw new AuthenticationException('Authentication required for this route');
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            throw new AuthenticationException('Invalid user for this route');
        }

        $userRoles = $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();
        $hasRole = false;
        foreach ($requiredRoles as $requiredRole) {
            if (in_array($requiredRole, $userRoles, true)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            throw new AuthenticationException(
                sprintf('Insufficient roles for route. Required: %s', implode(', ', $requiredRoles))
            );
        }
    }
}
