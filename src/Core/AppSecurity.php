<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Toolkit\A;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

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

        if (null === $firewallName) {
            return $this->controllerResolver($request);
        }

        $config = $this->getFirewallConfig($firewallName);
        $stateless = $config['stateless'] ?? false;

        if (($config['security'] ?? true) === false) {
            return $this->controllerResolver($request);
        }

        if ($this->isLogoutRequest($request, $config)) {
            $this->assertValidLogoutCsrfToken($request);

            return $this->logout($firewallName);
        }

        if ($token = $this->tryRestoreSessionToken($firewallName, $stateless)) {
            $token = $this->refreshUser($token);
            if (null === $token) {
                return $this->logout($firewallName);
            }
            try {
                $userChecker = $this->get(UserCheckerInterface::class);
                assert($userChecker instanceof UserCheckerInterface);
                $userChecker->checkPreAuth($token->getUser());
                $userChecker->checkPostAuth($token->getUser());
            } catch (AuthenticationException) {
                return $this->logout($firewallName);
            }
            $this->tokenStorage()->setToken($token);

            // CSRF protection for cookie/session-authenticated state changes.
            // Reached only on the restored-session path, so stateless firewalls
            // (REST APIs, GraphQL with bearer/API-key auth) are never checked.
            if ($csrfFailure = $this->enforceCsrf($request, $config)) {
                return $csrfFailure;
            }

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
            // A token minted from an ambient cookie credential (remember-me) is
            // forgeable cross-site the same way a restored session is, so a
            // state-changing first request must still carry a valid CSRF token.
            // Bearer/API-key tokens are not ambient (the browser does not attach
            // them automatically) and are intentionally exempt.
            if ($result instanceof RememberMeToken) {
                $csrfFailure = $this->enforceCsrf($request, $config);
                if (null !== $csrfFailure) {
                    return $csrfFailure;
                }
            }

            $this->tokenStorage()->setToken($result);
            if (!$stateless) {
                $session = $this->session();
                if (!$session->isStarted()) {
                    $session->start();
                }
                $session->set('_security_'.$firewallName, serialize($result));

                // Defend against session fixation: rotate the session ID once
                // the auth token has been associated with it. Any ID an attacker
                // might have pre-set on the victim becomes worthless.
                // false = preserve session data (auth token, flash bag).
                // (OWASP A07:2021)
                $session->migrate(false);

                // Rotate CSRF tokens at login — migrate(false) preserves session
                // data, so any pre-auth CSRF tokens that may have leaked
                // (referrer logs, shared-machine browser history) would otherwise
                // remain valid after authentication.
                $this->get(CsrfTokenManagerInterface::class)->clear();

                $session->save();
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

        $sessionKey = '_security_'.$firewallName;
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
        } catch (UserNotFoundException|UnsupportedUserException) {
            return null;
        }
    }

    /**
     * Check if the current request is a logout request.
     *
     * Logout MUST be POST to be safe from cross-site request forgery
     * (e.g. <img src="/logout"> or third-party links would otherwise log
     * the user out without their consent).
     */
    private function isLogoutRequest(ServerRequestInterface $request, array $config): bool
    {
        $logoutPath = A::get($config, 'logout.path');

        return $logoutPath
            && 'POST' === $request->getMethod()
            && $request->getUri()->getPath() === $logoutPath;
    }

    /**
     * Validate the CSRF token on a logout request.
     *
     * Token id is `logout`. Templates obtain it via
     * `$csrfTokenManager->getToken('logout')` and submit it as `_csrf_token`.
     *
     * @throws AuthenticationException when the token is missing or invalid
     */
    private function assertValidLogoutCsrfToken(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();
        $submitted = is_array($body) ? ($body['_csrf_token'] ?? null) : null;

        $manager = $this->get(CsrfTokenManagerInterface::class);
        assert($manager instanceof CsrfTokenManagerInterface);

        if (!is_string($submitted) || !$manager->validateToken('logout', $submitted)) {
            throw new AuthenticationException('Invalid CSRF token for logout.');
        }
    }

    /**
     * CSRF protection for session-authenticated, state-changing requests.
     *
     * Why this is safe for APIs: it runs only on the restored-session path of
     * handleAuthentication(), which stateless firewalls never reach. REST and
     * GraphQL endpoints configured as `stateless` authenticate with a bearer
     * token or API key — credentials the browser does NOT attach automatically —
     * so they cannot be driven cross-site and require no CSRF token.
     *
     * Safe HTTP methods (GET/HEAD/OPTIONS/TRACE) are never checked.
     *
     * Per-firewall configuration:
     *   'csrf'          => false   // disable CSRF entirely for this firewall
     *   'csrf_token_id' => 'csrf'  // session token id to validate against
     *
     * The token may be supplied as the `_csrf_token` body field or via an
     * `X-CSRF-Token` / `X-XSRF-Token` request header (for fetch/XHR clients).
     * Templates obtain it with `$csrfTokenManager->getToken('csrf')`.
     *
     * @return ResponseInterface|null a 403 response when the token is missing or
     *                                invalid, or null when the request may proceed
     *
     * @throws \JsonException
     */
    private function enforceCsrf(ServerRequestInterface $request, array $config): ?ResponseInterface
    {
        if (($config['csrf'] ?? true) === false) {
            return null;
        }

        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return null;
        }

        // The login entry point validates its own CSRF token (a different id)
        // inside the authenticator, so don't double-check it here.
        if (isset($config['entry_point']) && $request->getUri()->getPath() === $config['entry_point']) {
            return null;
        }

        $manager = $this->get(CsrfTokenManagerInterface::class);
        assert($manager instanceof CsrfTokenManagerInterface);

        $tokenId = $config['csrf_token_id'] ?? 'csrf';
        $submitted = $this->extractCsrfToken($request);

        if (is_string($submitted) && $manager->validateToken($tokenId, $submitted)) {
            return null;
        }

        return Response::json([
            'error' => 'invalid_csrf_token',
            'error_description' => 'Missing or invalid CSRF token.',
        ], 403);
    }

    /**
     * Read the submitted CSRF token from the request, preferring headers
     * (fetch/XHR) and falling back to the `_csrf_token` body field (forms).
     */
    private function extractCsrfToken(ServerRequestInterface $request): ?string
    {
        foreach (['X-CSRF-Token', 'X-XSRF-Token'] as $header) {
            if ($request->hasHeader($header)) {
                $value = trim($request->getHeaderLine($header));
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['_csrf_token'] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * Check if the current request is for an entry point page (login, 2FA).
     */
    private function isEntryPointPage(ServerRequestInterface $request, array $config): bool
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Allow entry point (login page)
        if (isset($config['entry_point'])
            && 'GET' === $method
            && $path === $config['entry_point']) {
            return true;
        }

        // Allow 2FA page (GET and POST) when there's a pending 2FA token
        $twoFactorPath = $config['two_factor_path'] ?? '/2fa';
        if (('GET' === $method || 'POST' === $method)
            && $path === $twoFactorPath
            && $this->session()->has('_2fa_token')) {
            return true;
        }

        // Allow 2FA cancel route
        if ($path === $twoFactorPath.'/cancel' && $this->session()->has('_2fa_token')) {
            return true;
        }

        return false;
    }

    /**
     * Try each configured authenticator until one succeeds.
     *
     * @throws \Exception
     */
    private function tryAuthenticators(
        ServerRequestInterface $request,
        array $config,
        string $firewallName,
        bool $stateless,
    ): TokenInterface|ResponseInterface|null {
        // Iterate in the order the firewall declares its authenticators, not the
        // order of the global registry. array_intersect_key() would key off the
        // registry, silently ignoring the firewall's intended precedence.
        $registry = $this->authenticators();

        foreach ($config['authenticators'] ?? [] as $name) {
            if (!isset($registry[$name])) {
                continue;
            }
            $authenticator = $registry[$name]($this);
            $supports = $authenticator->supports($request);

            if ($supports) {
                try {
                    $user = $authenticator->authenticate($request);

                    $userChecker = $this->get(UserCheckerInterface::class);
                    assert($userChecker instanceof UserCheckerInterface);
                    $userChecker->checkPreAuth($user);
                    $userChecker->checkPostAuth($user);

                    $token = $authenticator->createToken($user, $firewallName);

                    return $token;
                } catch (AuthenticationException $e) {
                    if (!$stateless && isset($config['entry_point'])) {
                        // If 2FA is required, create partial auth token and redirect to /2fa
                        if ($e instanceof TwoFactorRequiredException) {
                            $user = $e->getUser();
                            $twoFactorToken = new TwoFactorToken($user, $firewallName, $user->getRoles());

                            // Store partial token in session
                            $this->session()->set('_2fa_token', serialize($twoFactorToken));

                            // Set flash message for 2FA screen
                            $this->session()->getFlashBag()->add('info', '2FA code required');

                            // Return the authenticator's unauthorized response (redirect to /2fa)
                            return $authenticator->unauthorizedResponse($request, $e);
                        }

                        $this->session()->getFlashBag()->add('error', 'Invalid credentials.');

                        return null;
                    }
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
        $sessionKey = '_security_'.$firewallName;
        $this->session()->remove($sessionKey);
        $this->tokenStorage()->setToken(null);

        // Invalidate session if not stateless
        if (!($config['stateless'] ?? false)) {
            $this->session()->invalidate();
        }

        $response = Response::redirect($target);

        // Expire any remember-me cookies issued for this firewall. The session
        // is gone, but a surviving cookie would re-authenticate the user on the
        // next request — so clearing it is what makes logout actually log out.
        foreach ($this->rememberMeAuthenticators($config) as $rememberMe) {
            $response = $response->withAddedHeader('Set-Cookie', $rememberMe->buildClearCookieHeader());
        }

        return $response;
    }

    /**
     * Instantiate the firewall's configured remember-me authenticators.
     *
     * @return list<RememberMeAuthenticator>
     */
    private function rememberMeAuthenticators(array $config): array
    {
        $factories = array_intersect_key($this->authenticators(), array_flip($config['authenticators'] ?? []));

        $result = [];
        foreach ($factories as $factory) {
            $authenticator = $factory($this);
            if ($authenticator instanceof RememberMeAuthenticator) {
                $result[] = $authenticator;
            }
        }

        return $result;
    }

    // ============================================================================
    // AUTHORIZATION
    // ============================================================================

    /**
     * Enforces global access control rules.
     *
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
                throw new MethodNotAllowedException($rule['methods'], 'Method not allowed for this path: '.$path);
            }

            if (isset($rule['requires_channel']) && 'https' === $rule['requires_channel'] && 'https' !== $request->getUri()->getScheme()) {
                throw new AuthenticationException('HTTPS required for this path: '.$path);
            }

            if (!empty($rule['ips'])) {
                $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

                if (!IpUtils::checkIp($clientIp, $rule['ips'])) {
                    throw new AuthenticationException('Access denied due to IP restriction for path: '.$path);
                }
            }

            if (!empty($rule['roles'])) {
                $token = $this->tokenStorage()->getToken();
                if (null === $token) {
                    throw new AuthenticationException('Authentication required for path: '.$path);
                }
                $user = $token->getUser();
                if (!$user instanceof UserInterface) {
                    throw new AuthenticationException('Invalid user for path: '.$path);
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
                    throw new AuthenticationException('Insufficient roles for path: '.$path);
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
        if (!isset($pattern[0]) || '/' !== $pattern[0]) {
            $pattern = '/'.ltrim($pattern, '/');
        }

        // Match on full path segments, not a bare string prefix (audit L4):
        // a rule for "/admin" must NOT match "/administrator". The path either
        // equals the pattern exactly, or continues with a "/" after it.
        $normalized = rtrim($pattern, '/');

        return $path === $normalized
            || str_starts_with($path, $normalized.'/');
    }

    /**
     * Enforces access control based on #[IsGranted] roles in route defaults.
     *
     * @throws AuthenticationException
     */
    private function enforceAttributeAccessControl(array $parameters): void
    {
        $requiredRoles = $parameters['_is_granted_roles'] ?? [];
        if (empty($requiredRoles)) {
            return;
        }

        $token = $this->tokenStorage()->getToken();
        if (null === $token || !$token->getUser()) {
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
            throw new AuthenticationException(sprintf('Insufficient roles for route. Required: %s', implode(', ', $requiredRoles)));
        }
    }
}
