<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\Authenticator\AmbientCredentialInterface;
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AccountStatusException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\FirewallConfiguration;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
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
use Symfony\Component\Config\Definition\Processor;

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
    /**
     * Reserved switch-user identifier that ends an impersonation and returns
     * the session to the impersonator's own account.
     */
    public const SWITCH_USER_EXIT = '_exit';

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
            $user = $token->getUser();

            if (null === $user) {
                return $this->logout($firewallName);
            }

            try {
                $userChecker = $this->get(UserCheckerInterface::class);
                assert($userChecker instanceof UserCheckerInterface);
                $userChecker->checkPreAuth($user);
                $userChecker->checkPostAuth($user);
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

            // Impersonation runs on an already-authenticated token and only on
            // this path — the same position Symfony's SwitchUserListener holds
            // in the firewall chain: after the session context is restored and
            // CSRF is settled, before access control and the controller.
            if (null !== ($switched = $this->handleSwitchUser($request, $config, $firewallName))) {
                return $switched;
            }

            return $this->controllerResolver($request);
        }

        if ($this->isEntryPointPage($request, $config)) {
            // Cancelling a pending 2FA login is a state change on a
            // framework-hardcoded route, so the kernel owns its CSRF check —
            // unlike POST {two_factor_path} (the code submission), whose
            // token the 2FA controller validates itself under its own token
            // id, exactly like the login entry point.
            if ($this->isTwoFactorCancelRequest($request, $config)
                && null !== ($csrfFailure = $this->enforceCsrf($request, $config))) {
                return $csrfFailure;
            }

            return $this->controllerResolver($request);
        }

        // Set-Cookie headers expiring ambient credentials (remember-me cookies)
        // that failed to validate — attached to whichever response this request
        // produces, otherwise the browser re-presents the dead cookie forever.
        $staleCookies = [];

        // Set-Cookie headers re-issuing a rotated persistent remember-me cookie
        // (see RememberMeAuthenticator persistent mode), attached to the response
        // so the browser stores the freshly rotated value.
        $reissueCookies = [];

        $ambientCredential = false;
        $result = $this->tryAuthenticators($request, $config, $firewallName, $stateless, $staleCookies, $reissueCookies, $ambientCredential);

        // Handle ResponseInterface (e.g., 2FA redirect)
        if ($result instanceof ResponseInterface) {
            return $this->withStaleCookiesExpired($result, $staleCookies);
        }

        // Handle TokenInterface (successful authentication)
        if ($result instanceof TokenInterface) {
            // A token minted from an ambient credential — one the browser
            // re-attaches on its own, such as a remember-me cookie or a cached
            // HTTP Basic realm — is forgeable cross-site the same way a
            // restored session is, so a state-changing first request must still
            // carry a valid CSRF token. Bearer/API-key tokens are not ambient
            // and are intentionally exempt. Keyed on the authenticator rather
            // than the token class: BasicAuthenticator also mints a plain
            // UsernamePasswordToken, so a token-class check silently missed it.
            if ($ambientCredential) {
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

            // Re-send the rotated persistent remember-me cookie (if any). Only
            // the restore path produces these, so it does not conflict with the
            // fresh-login issuance above.
            foreach ($reissueCookies as $header) {
                $response = $response->withAddedHeader('Set-Cookie', $header);
            }

            return $response;
        }

        // Nobody authenticated. A path declared public is served anonymously
        // instead of being bounced to the entry point — the authenticators ran
        // first, so a remember-me cookie still signs the visitor in.
        // See SecurityConfigurator::publicPath() for how paths opt in.
        if ($this->accessDecisionEngine()->isPublic($request, $firewallName)) {
            // An anonymous session can still carry state worth forging a
            // request against (a guest cart, wizard progress), and its
            // cookie is just as ambient as an authenticated one — so
            // state-changing methods on public paths need a CSRF token too.
            // Stateless firewalls have no session to bind a token to and are
            // skipped, same as on the restored-session path; webhook-style
            // public POST endpoints opt out per firewall via `csrf => false`
            // or a `csrf_validator`.
            if (!$stateless && null !== ($csrfFailure = $this->enforceCsrf($request, $config))) {
                return $this->withStaleCookiesExpired($csrfFailure, $staleCookies);
            }

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
     *
     * @param array<string, mixed> $config
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
     * @param array<string, mixed> $config
     *
     * @throws AuthenticationException when no valid token is presented
     */
    private function assertValidLogoutCsrfToken(ServerRequestInterface $request, array $config): void
    {
        $this->assertValidActionCsrfToken($request, $config, 'logout', 'logout');
    }

    /**
     * Validate the CSRF token on a framework-owned, state-changing action
     * (logout, switch-user) that no controller gets to guard itself.
     *
     * Two equivalent proofs are accepted:
     *
     *  - HTML forms submit the action's dedicated token as the `_csrf_token`
     *    body field, obtained via `$csrfTokenManager->getToken($formTokenId)`.
     *  - fetch/XHR clients (SPAs) send the firewall's session token
     *    (`csrf_token_id`, default `csrf`) in the `X-CSRF-Token` /
     *    `X-XSRF-Token` header — the same header the general CSRF layer
     *    accepts for every other state-changing request.
     *
     * Both prove the same thing: same-origin JavaScript or markup with access
     * to the user's session minted the request. This runs independently of the
     * firewall's `csrf` setting — these actions are reachable on any firewall
     * and are exactly the ones an attacker most wants to trigger cross-site.
     *
     * @param array<string, mixed> $config
     *
     * @throws AuthenticationException when no valid token is presented
     */
    private function assertValidActionCsrfToken(
        ServerRequestInterface $request,
        array $config,
        string $formTokenId,
        string $purpose,
    ): void {
        $manager = $this->csrfTokenManager();

        $body = $request->getParsedBody();
        $bodyToken = is_array($body) ? ($body['_csrf_token'] ?? null) : null;

        if (is_string($bodyToken) && $manager->validateToken($formTokenId, $bodyToken)) {
            return;
        }

        $tokenId = $config['csrf_token_id'] ?? 'csrf';

        foreach (['X-CSRF-Token', 'X-XSRF-Token'] as $header) {
            $value = trim($request->getHeaderLine($header));
            if ('' !== $value && $manager->validateToken($tokenId, $value)) {
                return;
            }
        }

        throw new AuthenticationException(sprintf('Invalid CSRF token for %s.', $purpose));
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
     * @param array<string, mixed> $config
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

        // Paths whose controller validates its own CSRF token — a Symfony form
        // with form-level CSRF, a component with a per-form token id. The
        // kernel steps aside so that layer can answer with its own failure
        // shape (a re-rendered form with a field error, a 422) instead of the
        // kernel's hard 403 — the right response for a public form whose
        // visitor's session simply expired mid-compose. Delegation is per
        // path (firewall pattern syntax), so the rest of the firewall keeps
        // the kernel check; the delegated controller MUST actually validate.
        $path = $this->securityPath($request);
        foreach ($config['csrf_delegated_paths'] ?? [] as $pattern) {
            if (is_string($pattern) && RequestMatcher::matches($pattern, $path)) {
                return null;
            }
        }

        $manager = $this->csrfTokenManager();

        // Symfony forms namespace their token as `<name>[_token]` and key it
        // by a form-specific token id — a shape the flat `_csrf_token`
        // extraction below cannot see. Declaring the (form name → token id)
        // pairs lets such a form authorise the request declaratively, as an
        // ADDITIONAL accepted proof: when none matches, the header and
        // `_csrf_token` checks below still run as usual.
        //
        //   'csrf_form_tokens' => ['contact' => 'contact_form']
        //     → accepts body field `contact[_token]` validated against the
        //       token id `contact_form` (the form type's `csrf_token_id`).
        $formTokens = $config['csrf_form_tokens'] ?? [];
        if ([] !== $formTokens) {
            $body = $request->getParsedBody();
            foreach ($formTokens as $form => $tokenId) {
                $token = is_array($body) ? ($body[$form]['_token'] ?? null) : null;
                if (is_string($token) && is_string($tokenId) && $manager->validateToken($tokenId, $token)) {
                    return null;
                }
            }
        }

        // A form layer with a shape neither the built-in extraction nor
        // `csrf_form_tokens` can express supplies a validator instead of
        // being forced to disable CSRF wholesale.
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
     *
     * @param array<string, mixed> $config
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

        // Allow the 2FA cancel route. POST only: cancelling wipes the pending
        // 2FA token from the session — a state change — so it must not be
        // reachable through a cross-site GET (an <img> tag would do). The
        // caller additionally enforces the CSRF token on it.
        if ('POST' === $method
            && $path === $twoFactorPath.'/cancel'
            && $this->session()->has('_2fa_token')) {
            return true;
        }

        return false;
    }

    /**
     * Whether this request is the framework-hardcoded 2FA cancel action.
     *
     * @param array<string, mixed> $config
     */
    private function isTwoFactorCancelRequest(ServerRequestInterface $request, array $config): bool
    {
        $twoFactorPath = $config['two_factor_path'] ?? '/2fa';

        return 'POST' === $request->getMethod()
            && $this->securityPath($request) === $twoFactorPath.'/cancel';
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
     * @param array<string, mixed> $config
     * @param list<string>         $reissueCookies Set-Cookie headers re-issuing rotated
     *                                             remember-me cookies
     * @param list<string>         $staleCookies   Filled with Set-Cookie headers that
     *                                             expire remember-me cookies invalidated
     *                                             by this attempt; the caller attaches
     *                                             them to whatever response it returns
     *
     * @throws \Exception
     */
    private function tryAuthenticators(
        ServerRequestInterface $request,
        array $config,
        string $firewallName,
        bool $stateless,
        array &$staleCookies = [],
        array &$reissueCookies = [],
        bool &$ambientCredential = false,
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

                    // Whether the browser attaches this credential by itself,
                    // which decides if the caller must enforce CSRF.
                    $ambientCredential = $authenticator instanceof AmbientCredentialInterface;

                    // Persistent remember-me rotates its cookie value on each
                    // use; carry the fresh Set-Cookie back so the caller can
                    // attach it to the response.
                    if ($authenticator instanceof RememberMeAuthenticator
                        && null !== ($rotated = $authenticator->consumePendingCookieHeader())) {
                        $reissueCookies[] = $rotated;
                    }

                    return $token;
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

                            // Rotate the session id before binding the pending
                            // 2FA state to it, for the same reason the
                            // successful-login path migrates (see above): the
                            // password has just been proven, so any id an
                            // attacker pre-set on the victim must not survive
                            // into the authenticated session the 2FA step is
                            // about to produce. Without this, enabling 2FA
                            // would REMOVE the fixation protection a
                            // password-only login already has, because the
                            // authenticator-success path that migrates is
                            // never reached for a 2FA account.
                            $session = $this->session();
                            if (!$session->isStarted()) {
                                $session->start();
                            }
                            $session->migrate(true);
                            $this->csrfTokenManager()->clear();

                            // Store partial token in session
                            $session->set('_2fa_token', serialize($twoFactorToken));

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
     *
     * @param array<string, mixed> $config
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
     * Handle a user-impersonation request ("su"), when the firewall enables it:
     *
     *     'switch_user' => [
     *         'enabled'   => true,
     *         'role'      => 'ROLE_SUPER_ADMIN',   // the impersonator must hold this
     *         'parameter' => '_switch_user',       // carries the target identifier
     *         'target'    => '/dashboard',         // optional landing path
     *     ]
     *
     * Sending `{parameter}={identifier}` switches the session to that user;
     * sending the reserved value `_exit` (SWITCH_USER_EXIT) returns to the
     * impersonator's own account. Returns null when the request is not a
     * switch-user request, so the caller continues normally.
     *
     * Unlike Symfony — whose listener also accepts a plain `?_switch_user=…`
     * link — appkit requires POST plus a CSRF token, exactly as it does for
     * logout. A GET link would let `<img src="/panel?_switch_user=victim">` on
     * any third-party page silently switch an administrator's session into
     * another account, and the impersonator's own identity is the last thing
     * that should be forgeable cross-site.
     *
     * @param array<string, mixed> $config
     *
     * @throws AccessDeniedException   when the impersonator lacks the configured role
     * @throws AuthenticationException when the CSRF token is missing or invalid
     */
    private function handleSwitchUser(
        ServerRequestInterface $request,
        array $config,
        string $firewallName,
    ): ?ResponseInterface {
        $switch = $config['switch_user'] ?? null;

        if (!is_array($switch) || true !== ($switch['enabled'] ?? false)) {
            return null;
        }

        if ('POST' !== $request->getMethod()) {
            return null;
        }

        $parameter = $switch['parameter'] ?? '_switch_user';
        $body = $request->getParsedBody();
        $identifier = is_array($body) ? ($body[$parameter] ?? null) : null;

        // Identifiers can be falsy-looking strings ("0"), so only null/'' opt out.
        if (!is_string($identifier) || '' === $identifier) {
            return null;
        }

        $this->assertValidActionCsrfToken($request, $config, 'switch_user', 'switch user');

        $token = $this->tokenStorage()->getToken();

        if (null === $token) {
            throw new AuthenticationException('Could not find original Token object.');
        }

        $newToken = self::SWITCH_USER_EXIT === $identifier
            ? $this->attemptExitSwitchUser($token)
            : $this->attemptSwitchUser($request, $token, $identifier, $switch, $firewallName);

        $this->tokenStorage()->setToken($newToken);

        if (!($config['stateless'] ?? false)) {
            $session = $this->session();
            if (!$session->isStarted()) {
                $session->start();
            }
            $session->set('_security_'.$firewallName, serialize($newToken));

            // The effective identity just changed — the same reason login
            // migrates the session. Without this, the pre-switch session ID
            // stays valid while carrying the impersonated identity.
            $session->migrate(true);
            $session->save();
        }

        return Response::redirect(
            $this->switchUserTarget($request, $switch, $token, $newToken)
        );
    }

    /**
     * Build the token impersonating $identifier, after checking the current
     * token is allowed to.
     *
     * @param array<string, mixed> $switch
     *
     * @throws AccessDeniedException
     */
    private function attemptSwitchUser(
        ServerRequestInterface $request,
        #[\SensitiveParameter] TokenInterface $token,
        string $identifier,
        array $switch,
        string $firewallName,
    ): TokenInterface {
        // Already impersonating: unwind first, so the original token always
        // holds the real administrator. Chained switches must not nest — that
        // would make "exit" step back into another impersonation.
        if ($token instanceof SwitchUserToken) {
            if ($token->getUserIdentifier() === $identifier) {
                return $token;
            }

            $token = $this->attemptExitSwitchUser($token);
        }

        $role = $switch['role'] ?? 'ROLE_ALLOWED_TO_SWITCH';

        // Decided through the role hierarchy, so configuring
        // 'role' => 'ROLE_SUPER_ADMIN' also admits anything that reaches it.
        if (!$this->accessDecisionEngine()->isGranted([$role], $token)) {
            throw new AccessDeniedException(sprintf('Switching users requires %s.', $role));
        }

        try {
            $targetUser = $this->userProvider()->loadUserByIdentifier($identifier);

            $userChecker = $this->get(UserCheckerInterface::class);
            assert($userChecker instanceof UserCheckerInterface);
            $userChecker->checkPreAuth($targetUser);
            $userChecker->checkPostAuth($targetUser);
        } catch (AuthenticationException|AccountStatusException) {
            // Deliberately indistinguishable from "not allowed": a caller who
            // has cleared the role check must not be able to probe which
            // identifiers exist, or which accounts are locked or expired.
            throw new AccessDeniedException('Switch user failed.');
        }

        return new SwitchUserToken(
            user: $targetUser,
            firewallName: $firewallName,
            roles: $targetUser->getRoles(),
            originalToken: $token,
            originatedFromUri: $this->switchUserOriginUri($request),
        );
    }

    /**
     * Restore the impersonator's own token.
     *
     * @throws AuthenticationException when the session is not impersonating
     */
    private function attemptExitSwitchUser(#[\SensitiveParameter] TokenInterface $token): TokenInterface
    {
        if (!$token instanceof SwitchUserToken) {
            throw new AuthenticationException('Could not find original Token object.');
        }

        $original = $token->getOriginalToken();

        // Re-read the impersonator from the provider: roles or account status
        // may have changed while the switch was in effect, and the returning
        // session must reflect that rather than the snapshot taken at switch time.
        return $this->refreshUser($original) ?? throw new AuthenticationException('Original user is no longer valid.');
    }

    /**
     * Where to land after switching or exiting.
     *
     * @param array<string, mixed> $switch
     */
    private function switchUserTarget(
        ServerRequestInterface $request,
        array $switch,
        #[\SensitiveParameter] TokenInterface $previousToken,
        #[\SensitiveParameter] TokenInterface $newToken,
    ): string {
        // Exiting returns to wherever the switch was started from, when known.
        if (!$newToken instanceof SwitchUserToken
            && $previousToken instanceof SwitchUserToken
            && null !== ($origin = $previousToken->getOriginatedFromUri())) {
            return $origin;
        }

        $body = $request->getParsedBody();
        $targetPath = is_array($body) ? ($body['_target_path'] ?? null) : null;

        // Only same-site paths — a caller-supplied absolute URL would turn the
        // redirect into an open redirect.
        if (is_string($targetPath) && null !== ($safe = $this->sameSitePath($targetPath, $request))) {
            return $safe;
        }

        $configured = $switch['target'] ?? null;

        return is_string($configured) && '' !== $configured ? $configured : '/';
    }

    /**
     * The page the impersonator was on when starting the switch, recorded on
     * the token so exiting can return there. Taken from the submitted target
     * path or the Referer, and only when it is a same-site absolute path.
     */
    private function switchUserOriginUri(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $candidates = [
            is_array($body) ? ($body['_target_path'] ?? null) : null,
            $request->getHeaderLine('Referer'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || '' === $candidate) {
                continue;
            }

            $path = $this->sameSitePath($candidate, $request);

            if (null !== $path) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Reduce a submitted path or a Referer to a same-site absolute path, or
     * null when it points anywhere else. Used to keep switch-user redirects
     * from becoming an open redirect.
     */
    private function sameSitePath(string $candidate, ServerRequestInterface $request): ?string
    {
        // A Referer is an absolute URL: accept it only when its host matches
        // ours, and keep just the path.
        if (null !== parse_url($candidate, PHP_URL_SCHEME)) {
            if (parse_url($candidate, PHP_URL_HOST) !== $request->getUri()->getHost()) {
                return null;
            }

            $candidate = parse_url($candidate, PHP_URL_PATH) ?: '/';
        }

        // A single leading slash only — `//evil.example` is a protocol-relative
        // URL, which the browser would follow off-site.
        //
        // Backslashes are folded first: the WHATWG URL parser treats `\` as `/`
        // while reading the authority, so `/\evil.example` resolves to
        // `//evil.example` and leaves the site just as surely. Normalising is
        // safer than enumerating the shapes — `/\`, `/\/`, `\\` and friends all
        // collapse to the same check.
        $normalised = str_replace('\\', '/', $candidate);

        return str_starts_with($normalised, '/') && !str_starts_with($normalised, '//')
            ? $candidate
            : null;
    }

    /**
     * Attach a signed remember-me cookie to the response when the just-completed
     * interactive login opted in via the configured parameter (default
     * `_remember_me`). A no-op when the firewall has no remember-me authenticator
     * or the opt-in parameter is absent, so ordinary logins are unaffected.
     *
     * @param array<string, mixed> $config
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
     * @param array<string, mixed> $config
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
     * @param array<string, mixed> $parameters
     *
     * @see AccessDecisionEngine::enforceRoleGroups()
     *
     * @throws AuthenticationException
     */
    private function enforceAttributeAccessControl(array $parameters, ?ServerRequestInterface $request = null): void
    {
        $this->accessDecisionEngine()->enforceRoleGroups(
            $parameters['_is_granted_roles'] ?? [],
            $this->tokenStorage()->getToken(),
            $request?->getMethod(),
        );
    }

    // ============================================================================
    // FIREWALL & SECURITY CONFIGURATION
    // ============================================================================

    /**
     * @param array<string, mixed> $config
     */
    public function configureFirewall(array $config): self
    {
        $this->assertValidFirewallConfig($config['firewalls'] ?? []);
        $this->firewallConfig = $config['firewalls'] ?? [];
        $this->accessControlRules = $config['access_control'] ?? [];
        $this->roleHierarchy = new RoleHierarchy($config['role_hierarchy'] ?? []);
        $this->denyUnmatchedAccess = (bool) ($config['deny_unmatched'] ?? false);
        $this->accessDecisionEngine = null;

        // Sync firewall config to application state if it exists
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    /**
     * Configure security using SecurityConfigurator (new fluent API).
     */
    public function configureSecurity(SecurityConfigurator $configurator): static
    {
        $this->assertValidFirewallConfig($configurator->getFirewalls());
        $this->firewallConfig = $configurator->getFirewalls();
        $this->accessControlRules = $configurator->getAccessControlRules();
        $this->roleHierarchy = $configurator->getRoleHierarchy();
        $this->denyUnmatchedAccess = $configurator->deniesUnmatchedRequests();
        $this->accessDecisionEngine = null;
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    /**
     * Validate firewall configuration against the FirewallConfiguration schema.
     *
     * Type-checks the keys appkit itself consumes and rejects `methods` — a key
     * that reads as a per-method firewall filter but is silently ignored by
     * firewall selection (which matches on pattern alone), so it fails open.
     * App-specific keys the schema does not know are passed through untouched,
     * since firewall config is handed to the app's own ApplicationState.
     *
     * Skipped in prod. Config configuration runs on every request in appkit's
     * per-request boot, and building + normalizing the schema tree is not free;
     * paying that on every production hit to re-check config that has not
     * changed since deploy mirrors nothing Symfony does — Symfony validates at
     * container compile time and serves a cached, pre-validated result at
     * runtime. Here the equivalent is to validate in dev/test (and CI), where
     * the config is authored and the failure is wanted loud and immediate, and
     * to trust the already-validated config in prod. Deploys that never run
     * dev/test should validate in CI (the schema is public: run the Processor
     * against FirewallConfiguration there).
     *
     * @param array<string, array<string, mixed>> $firewalls
     *
     * @throws \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    private function assertValidFirewallConfig(array $firewalls): void
    {
        if ($this->environment()->isProd()) {
            return;
        }

        (new Processor())->processConfiguration(
            new FirewallConfiguration(),
            [['firewalls' => $firewalls]],
        );
    }

    /**
     * @return array<string, \Closure>
     */
    public function authenticators(): array
    {
        return $this->authenticators;
    }

    /**
     * Register or override an authenticator factory at runtime.
     *
     * Useful for tests that need a specific authenticator configuration
     * without modifying the global config file.
     */
    public function registerAuthenticator(string $name, \Closure $factory): static
    {
        $this->authenticators[$name] = $factory;

        return $this;
    }

    public function getFirewallName(string $path): ?string
    {
        if (null === $this->state) {
            throw new \RuntimeException('Firewall resolution is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getFirewallName($path);
    }

    /**
     * Resolve the firewall for a request, honouring pattern + methods + host +
     * ips restrictions (Symfony-style). Security-critical selection uses this.
     */
    public function getFirewallNameForRequest(ServerRequestInterface $request): ?string
    {
        if (null === $this->state) {
            throw new \RuntimeException('Firewall resolution is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getFirewallNameForRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFirewallConfig(string $firewallName): array
    {
        return $this->firewallConfig[$firewallName] ?? [];
    }

    /**
     * All configured firewalls, keyed by name, in declaration order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFirewalls(): array
    {
        return $this->firewallConfig;
    }

    /**
     * The configured access-control rules, in declaration order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAccessControlRules(): array
    {
        return $this->accessControlRules ?? [];
    }

    public function getRoleHierarchy(): ?RoleHierarchy
    {
        return $this->roleHierarchy;
    }

    /**
     * The engine enforcing access-control rules and #[IsGranted] attributes.
     *
     * Built lazily from the configured rules and role hierarchy; rebuilt when
     * configureFirewall()/configureSecurity() replaces the configuration.
     * Register custom rule constraints on it during application setup:
     *
     *   $app->accessDecisionEngine()->registerConstraint(new OfficeHoursConstraint());
     */
    public function accessDecisionEngine(): AccessDecisionEngine
    {
        return $this->accessDecisionEngine ??= new AccessDecisionEngine(
            rules: $this->accessControlRules ?? [],
            roleHierarchy: $this->roleHierarchy,
            denyByDefault: $this->denyUnmatchedAccess,
        );
    }
}
