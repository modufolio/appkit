<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AccountStatusException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
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
        $firewallName = $this->getFirewallNameForRequest($request);

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

        // Set-Cookie headers expiring ambient credentials (remember-me cookies)
        // that failed to validate — attached to whichever response this request
        // produces, otherwise the browser re-presents the dead cookie forever.
        $staleCookies = [];

        $result = $this->tryAuthenticators($request, $config, $firewallName, $stateless, $staleCookies);

        // Handle ResponseInterface (e.g., 2FA redirect)
        if ($result instanceof ResponseInterface) {
            return $this->withStaleCookiesExpired($result, $staleCookies);
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
                    return $this->withStaleCookiesExpired($csrfFailure, $staleCookies);
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
                //
                // $destroy = true DELETES the old session storage — it does not
                // touch the attributes, which migrate() always carries over to
                // the new ID (see SessionInterface::migrate). Passing false
                // would leave the pre-login ID valid until garbage collection,
                // and since the auth token was just written above, that stale ID
                // would remain a usable authenticated session — exactly the
                // fixation window this is meant to close. Symfony's
                // SessionAuthenticationStrategy::MIGRATE uses true for the same
                // reason. (OWASP A07:2021)
                $session->migrate(true);

                // Rotate CSRF tokens at login — migrate() preserves session
                // attributes, so any pre-auth CSRF tokens that may have leaked
                // (referrer logs, shared-machine browser history) would otherwise
                // remain valid after authentication.
                $this->csrfTokenManager()->clear();

                $session->save();
            }

            // Expire stale cookies BEFORE issuing a fresh one: with duplicate
            // Set-Cookie headers for the same name, the browser honors the
            // last one — the expiry must not clobber a cookie minted by the
            // login that just succeeded.
            $response = $this->withStaleCookiesExpired($this->controllerResolver($request), $staleCookies);

            // Auto-issue the remember-me cookie on a fresh interactive login,
            // so no controller has to assemble a Set-Cookie header itself.
            // Skipped for stateless firewalls and for the remember-me restore
            // path (that token is not an interactive login and carries no
            // opt-in parameter anyway).
            if (!$stateless && !($result instanceof RememberMeToken)) {
                $response = $this->issueRememberMeCookie($request, $config, $result, $response);
            }

            return $response;
        }

        // Nobody authenticated. A path declared public is served anonymously
        // instead of being bounced to the entry point — the authenticators ran
        // first, so a remember-me cookie still signs the visitor in.
        // See SecurityConfigurator::publicPath() for how paths opt in.
        if ($this->accessDecisionEngine()->isPublic($request, $firewallName)) {
            return $this->withStaleCookiesExpired($this->controllerResolver($request), $staleCookies);
        }

        return $this->withStaleCookiesExpired($this->handleEntryPointRedirect($config, $stateless), $staleCookies);
    }

    /**
     * Attach Set-Cookie headers that expire ambient credentials which failed
     * validation during this request (collected by tryAuthenticators()).
     *
     * @param list<string> $staleCookies
     */
    private function withStaleCookiesExpired(ResponseInterface $response, array $staleCookies): ResponseInterface
    {
        foreach ($staleCookies as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }

        return $response;
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
    private function refreshUser(#[\SensitiveParameter] TokenInterface $token): ?TokenInterface
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
            && $this->securityPath($request) === $logoutPath;
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
        $manager = $this->csrfTokenManager();

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
        if (isset($config['entry_point']) && $this->securityPath($request) === $config['entry_point']) {
            return null;
        }

        $manager = $this->csrfTokenManager();

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
            if (!$request->hasHeader($header)) {
                continue;
            }

            $value = trim($request->getHeaderLine($header));
            if ('' !== $value) {
                return $value;
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
        $path = $this->securityPath($request);
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
     * Failures are handled by credential kind:
     *
     *  - Ambient credentials (a remember-me cookie the browser attaches on its
     *    own) fail silently: nobody typed anything, so a dead cookie — expired,
     *    password changed, secret rotated — is not a failed login attempt and
     *    must not surface an error on every request until the cookie expires.
     *    The cookie is expired via $staleCookies and the request continues to
     *    the remaining authenticators, then anonymously.
     *
     *  - Interactive credentials (a submitted login form) flash the exception's
     *    getMessageKey() — the user-safe message, while getMessage() may carry
     *    internal detail for logs. Account-status failures (locked, disabled,
     *    expired) deliberately read as 'Invalid credentials.' so the response
     *    never confirms that an account exists. A failed interactive login also
     *    expires any remember-me cookie riding along on the request.
     *
     * See docs/security.md § "Authentication failure behaviour".
     *
     * @param list<string> $staleCookies Filled with Set-Cookie headers that
     *                                   expire remember-me cookies invalidated
     *                                   by this attempt; the caller attaches
     *                                   them to whatever response it returns
     *
     * @throws \Exception
     */
    private function tryAuthenticators(
        ServerRequestInterface $request,
        array $config,
        string $firewallName,
        bool $stateless,
        array &$staleCookies = [],
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

                    return $authenticator->createToken($user, $firewallName);
                } catch (AuthenticationException $e) {
                    // Ambient credential: expire the dead cookie, continue anonymously.
                    if ($authenticator instanceof RememberMeAuthenticator) {
                        $staleCookies[] = $authenticator->buildClearCookieHeader();

                        continue;
                    }

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

                        // Account-status detail must not confirm the account exists.
                        $publicException = $e instanceof AccountStatusException
                            ? new BadCredentialsException('Account status rejected', 0, $e)
                            : $e;
                        $this->session()->getFlashBag()->add('error', $publicException->getMessageKey());

                        // A failed interactive login also invalidates remember-me cookies.
                        foreach ($this->rememberMeAuthenticators($config) as $rememberMe) {
                            $staleCookies[] = $rememberMe->buildClearCookieHeader();
                        }

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
     * Attach a signed remember-me cookie to the response when the just-completed
     * interactive login opted in via the configured parameter (default
     * `_remember_me`). A no-op when the firewall has no remember-me authenticator
     * or the opt-in parameter is absent, so ordinary logins are unaffected.
     */
    private function issueRememberMeCookie(
        ServerRequestInterface $request,
        array $config,
        #[\SensitiveParameter] TokenInterface $token,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $token->getUser();
        if (null === $user) {
            return $response;
        }

        foreach ($this->rememberMeAuthenticators($config) as $rememberMe) {
            if (!$this->requestOptedIntoRememberMe($request, $rememberMe->getRememberParameter())) {
                continue;
            }

            $response = $response->withAddedHeader(
                'Set-Cookie',
                $rememberMe->buildRememberMeCookieHeader($user),
            );
        }

        return $response;
    }

    /**
     * Whether the request opted into a persistent session. The opt-in is read
     * from the parsed body (login POST) first, then the query string, and
     * accepts the usual truthy encodings ("1"/"true"/"on"/true).
     */
    private function requestOptedIntoRememberMe(ServerRequestInterface $request, string $parameter): bool
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$parameter] ?? null) : null;

        if (null === $value) {
            $value = $request->getQueryParams()[$parameter] ?? null;
        }

        return null !== $value && filter_var($value, FILTER_VALIDATE_BOOL);
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
     * @see AccessDecisionEngine::enforce()
     *
     * @throws AuthenticationException
     */
    private function enforceAccessControl(ServerRequestInterface $request): void
    {
        $this->accessDecisionEngine()->enforce($request, $this->tokenStorage()->getToken());
    }

    /**
     * The request path as the router will see it, for security matching.
     *
     * @see RequestMatcher::securityPath()
     */
    private function securityPath(ServerRequestInterface $request): string
    {
        return RequestMatcher::securityPath($request->getUri());
    }

    /**
     * Enforces access control based on #[IsGranted] roles in route defaults.
     *
     * @see AccessDecisionEngine::enforceRoleGroups()
     *
     * @throws AuthenticationException
     */
    private function enforceAttributeAccessControl(array $parameters): void
    {
        $this->accessDecisionEngine()->enforceRoleGroups(
            $parameters['_is_granted_roles'] ?? [],
            $this->tokenStorage()->getToken(),
        );
    }
}
