<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthClientInterface;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * "Sign in with Google" for the panel.
 *
 * Handles the callback leg only: the browser returns from Google to the
 * callback path carrying a `code` and the `state` we issued. This verifies
 * the state (anti-forgery), turns the code into a verified Google identity,
 * and maps it onto an EXISTING panel user by email. It never provisions:
 * an address Google vouches for that nobody here owns is a failed login, not
 * a new account. Restricting who can sign in stays where it already is —
 * you add users the normal way, and this becomes another way for them in.
 *
 * The redirect that STARTS the flow is an ordinary controller action; only
 * the return trip needs to become an authenticated session, which is what an
 * authenticator is for.
 */
final class GoogleAuthenticator extends AbstractAuthenticator
{
    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array{
     *     callback_path?: string,
     *     login_path?: string,
     *     state_session_key?: string,
     *     allowed_hosted_domain?: string|null
     * } $options
     */
    public function __construct(
        private readonly GoogleOAuthClientInterface $client,
        private readonly UserProviderInterface $userProvider,
        private readonly SessionInterface $session,
        array $options = [],
    ) {
        $this->options = array_merge([
            'callback_path' => '/panel/auth/google/callback',
            'login_path' => '/panel/login',
            'state_session_key' => '_google_oauth_state',
            // When set, only accounts in this Workspace domain (`hd`) may sign
            // in — a second gate on top of the existing-user match.
            'allowed_hosted_domain' => null,
        ], $options);
    }

    public function supports(ServerRequestInterface $request): bool
    {
        if ($this->path($request) !== $this->options['callback_path']) {
            return false;
        }

        return is_string($request->getQueryParams()['code'] ?? null);
    }

    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        $query = $request->getQueryParams();
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;

        $this->assertValidState(is_string($state) ? $state : '');

        if (!is_string($code) || $code === '') {
            throw new BadCredentialsException('Missing authorization code.');
        }

        try {
            $identity = $this->client->authenticate($code);
        } catch (GoogleOAuthException $e) {
            // Every cause collapses to one message: a login attempt failed.
            throw new BadCredentialsException('Google sign-in failed.', 0, $e);
        }

        // An address the user has not proven they own is not identity.
        if (!$identity->emailVerified) {
            throw new BadCredentialsException('Google account email is not verified.');
        }

        $allowedDomain = $this->options['allowed_hosted_domain'];
        if (is_string($allowedDomain) && $allowedDomain !== '' && $identity->hostedDomain !== $allowedDomain) {
            throw new BadCredentialsException('Google account is outside the permitted domain.');
        }

        try {
            // Existing users only: an unknown email is indistinguishable from
            // a wrong password — a failed login, not a hint that it is unknown.
            return $this->userProvider->loadUserByIdentifier($identity->email);
        } catch (UserNotFoundException $e) {
            throw new BadCredentialsException('No panel account matches this Google email.', 0, $e);
        }
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        // Same token a form login mints, so a Google session is
        // indistinguishable afterwards — switch-user, logout and role checks
        // all behave identically.
        return new UsernamePasswordToken($user, $firewallName, $user->getRoles());
    }

    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::redirect($this->options['login_path'], 303);
    }

    /**
     * The `state` we issued must come back exactly, and only once.
     *
     * It is removed on read: a callback URL can be replayed from history or a
     * referrer log, and a one-time state makes that replay inert.
     *
     * @throws AuthenticationException
     */
    private function assertValidState(string $state): void
    {
        $expected = $this->session->get($this->options['state_session_key']);
        $this->session->remove($this->options['state_session_key']);

        if (!is_string($expected) || $expected === '' || $state === '' || !hash_equals($expected, $state)) {
            throw new BadCredentialsException('Invalid OAuth state.');
        }
    }

    private function path(ServerRequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }
}
