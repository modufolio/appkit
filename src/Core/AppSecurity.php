<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\EquatableInterface;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Toolkit\A;
use Modufolio\Psr7\Http\Response;
use Negotiation\BaseAccept;
use Negotiation\Negotiator;
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
            $this->assertValidLogoutCsrfToken($request, $config);

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

        // Nobody authenticated. A path declared public is served anonymously
        // instead of being bounced to the entry point — the authenticators ran
        // first, so a remember-me cookie still signs the visitor in.
        if ($this->isPublicRequest($request)) {
            return $this->controllerResolver($request);
        }

        return $this->handleEntryPointRedirect($config, $stateless);
    }

    /**
     * Whether an access-control rule declares this request public.
     *
     * A rule may narrow the exemption to certain methods, so that e.g. a page
     * is readable anonymously while writing to it still requires a login.
     *
     * @see SecurityConfigurator::publicPath()
     */
    private function isPublicRequest(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        foreach ($this->accessControlRules ?? [] as $rule) {
            if (!in_array(SecurityConfigurator::PUBLIC_ACCESS, $rule['roles'] ?? [], true)) {
                continue;
            }

            if (!$this->matchesAccessControlPattern($rule['path'] ?? '/', $path)) {
                continue;
            }

            if (empty($rule['methods']) || in_array($method, $rule['methods'], true)) {
                return true;
            }
        }

        return false;
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

            // If security-relevant state changed (roles, password, identity),
            // the session is no longer trustworthy: force re-authentication.
            if ($this->hasUserChanged($user, $refreshedUser)) {
                return null;
            }

            $newToken = clone $token;
            $newToken->setUser($refreshedUser);

            return $newToken;
        } catch (UserNotFoundException|UnsupportedUserException) {
            return null;
        }
    }

    /**
     * Whether the refreshed user differs from the session user in a way that
     * should invalidate the existing session (revoked roles, changed password).
     */
    private function hasUserChanged(UserInterface $original, UserInterface $refreshed): bool
    {
        if ($original instanceof EquatableInterface) {
            return !$original->isEqualTo($refreshed);
        }

        if ($refreshed instanceof EquatableInterface) {
            return !$refreshed->isEqualTo($original);
        }

        if ($original->getRoles() !== $refreshed->getRoles()) {
            return true;
        }

        if ($original instanceof PasswordAuthenticatedUserInterface
            && $refreshed instanceof PasswordAuthenticatedUserInterface
            && $original->getPassword() !== $refreshed->getPassword()) {
            return true;
        }

        return $original->getUserIdentifier() !== $refreshed->getUserIdentifier();
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
     * Two equivalent proofs are accepted, mirroring enforceCsrf():
     *
     *  - HTML forms submit the dedicated `logout` token as the
     *    `_csrf_token` body field. Templates obtain it via
     *    `$csrfTokenManager->getToken('logout')`.
     *  - fetch/XHR clients (SPAs) send the firewall's session token
     *    (`csrf_token_id`, default `csrf`) via the `X-CSRF-Token` /
     *    `X-XSRF-Token` header — the same header the general CSRF
     *    layer accepts for every other state-changing request.
     *
     * Both prove the same thing: same-origin JavaScript or markup with
     * access to the user's session minted the request.
     *
     * @throws AuthenticationException when no valid token is presented
     */
    private function assertValidLogoutCsrfToken(ServerRequestInterface $request, array $config): void
    {
        $manager = $this->get(CsrfTokenManagerInterface::class);
        assert($manager instanceof CsrfTokenManagerInterface);

        $body = $request->getParsedBody();
        $bodyToken = is_array($body) ? ($body['_csrf_token'] ?? null) : null;

        if (is_string($bodyToken) && $manager->validateToken('logout', $bodyToken)) {
            return;
        }

        $tokenId = $config['csrf_token_id'] ?? 'csrf';

        foreach (['X-CSRF-Token', 'X-XSRF-Token'] as $header) {
            $value = trim($request->getHeaderLine($header));
            if ('' !== $value && $manager->validateToken($tokenId, $value)) {
                return;
            }
        }

        throw new AuthenticationException('Invalid CSRF token for logout.');
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
     *   'csrf'           => false   // disable CSRF entirely for this firewall
     *   'csrf_token_id'  => 'csrf'  // session token id to validate against
     *   'csrf_validator' => callable(ServerRequestInterface, CsrfTokenManagerInterface): ?bool
     *
     * The token may be supplied as the `_csrf_token` body field or via an
     * `X-CSRF-Token` / `X-XSRF-Token` request header (for fetch/XHR clients).
     * Templates obtain it with `$csrfTokenManager->getToken('csrf')`.
     *
     * `csrf_validator` covers request shapes the built-in extraction cannot
     * describe — a namespaced form field, a per-form token id, or a form layer
     * that validates its own token. Return true to accept, false to reject, or
     * null to fall through to the check above:
     *
     *   'csrf_validator' => function ($request, $tokens) {
     *       $body = $request->getParsedBody();
     *
     *       return isset($body['contact']['_token'])
     *           ? $tokens->validateToken('contact_form', $body['contact']['_token'])
     *           : null;
     *   },
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

        // A form layer that names its field differently, namespaces it
        // (`contact[_token]`) or keys the token by form rather than by firewall
        // cannot be expressed by the built-in extraction. Such an app supplies
        // a validator instead of being forced to disable CSRF wholesale.
        $validator = $config['csrf_validator'] ?? null;

        if (is_callable($validator)) {
            $verdict = $validator($request, $manager);

            if (true === $verdict) {
                return null;
            }

            if (false === $verdict) {
                return $this->csrfFailureResponse($request);
            }

            // null → the validator has no opinion, fall through to the default.
        }

        $tokenId = $config['csrf_token_id'] ?? 'csrf';

        if ($manager->validateToken($tokenId, $this->extractCsrfToken($request))) {
            return null;
        }

        return $this->csrfFailureResponse($request);
    }

    /**
     * The response for a missing or invalid CSRF token.
     *
     * A browser posting a form gets whatever the app's exception handler
     * renders for AccessDeniedException (an HTML page, typically); fetch/XHR
     * and API clients keep the JSON body this has always returned.
     *
     * @throws \JsonException
     */
    private function csrfFailureResponse(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->clientPrefersHtml($request)) {
            return $this->exceptionHandler()->handle(
                new AccessDeniedException('Missing or invalid CSRF token.'),
                $request,
            );
        }

        return Response::json([
            'error' => 'invalid_csrf_token',
            'error_description' => 'Missing or invalid CSRF token.',
        ], 403);
    }

    /**
     * Whether the client asked for HTML ahead of anything else — a form
     * submission from a browser, as opposed to fetch/XHR or an API client.
     *
     * Negotiated with the same library the exception handler uses, so the two
     * agree on what a request wants: this decides whether to hand the failure
     * to the handler, and the handler then picks its formatter the same way.
     * JSON leads the priority list, so a client expressing no preference
     * (`Accept: *\/*`, or no header at all) gets the machine-readable body.
     */
    private function clientPrefersHtml(ServerRequestInterface $request): bool
    {
        // An XHR that announces itself wants data back, whatever it accepts.
        if ('xmlhttprequest' === strtolower($request->getHeaderLine('X-Requested-With'))) {
            return false;
        }

        $accept = $request->getHeaderLine('Accept');

        if ('' === $accept) {
            return false;
        }

        $best = (new Negotiator())->getBest($accept, ['application/json', 'text/html']);

        return $best instanceof BaseAccept && 'text/html' === $best->getValue();
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

            // A PUBLIC_ACCESS rule only waives the authentication redirect
            // (see isPublicRequest()); it neither grants nor restricts anything
            // here, so later rules still get their say.
            if (in_array(SecurityConfigurator::PUBLIC_ACCESS, $rule['roles'] ?? [], true)) {
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
                    throw new AccessDeniedException('Access denied due to IP restriction for path: '.$path);
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
                    throw new AccessDeniedException('Insufficient roles for path: '.$path);
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
        $requiredRoleGroups = $parameters['_is_granted_roles'] ?? [];
        if (empty($requiredRoleGroups)) {
            return;
        }

        $token = $this->tokenStorage()->getToken();
        if (null === $token || !$token->getUser()) {
            throw new AuthenticationException('Authentication required for this route');
        }

        $user = $token->getUser();

        $userRoles = $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();

        // Every group (one per #[IsGranted]) must be satisfied (AND); within a
        // group, holding any one of the listed roles is enough (OR). The (array)
        // cast tolerates a legacy flat list from a stale compiled-route cache.
        foreach ($requiredRoleGroups as $group) {
            $group = (array) $group;
            $satisfied = false;

            foreach ($group as $role) {
                if (in_array($role, $userRoles, true)) {
                    $satisfied = true;
                    break;
                }
            }

            if (!$satisfied) {
                throw new AccessDeniedException(sprintf('Insufficient roles for route. Required one of: %s', implode(', ', $group)));
            }
        }
    }
}
