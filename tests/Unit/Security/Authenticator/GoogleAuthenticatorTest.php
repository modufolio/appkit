<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Appkit\Security\Authenticator\GoogleAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\OAuth\Google\GoogleIdentity;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthClientInterface;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthException;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorSecret;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorServiceInterface;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\InMemoryUserProvider;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class GoogleAuthenticatorTest extends AppTestCase
{
    private const CALLBACK = '/panel/auth/google/callback';
    private const STATE_KEY = '_google_oauth_state';

    private InMemoryUserProvider $userProvider;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userProvider = new InMemoryUserProvider();
        $this->userProvider->addUser(new InMemoryUser('member@example.com', 'x', ['ROLE_USER']));

        $this->session = new Session(new MockArraySessionStorage());
    }

    /** A fake client that returns a fixed identity, or throws. */
    private function client(?GoogleIdentity $identity, ?\Throwable $throw = null, ?\Closure $onAuthenticate = null): GoogleOAuthClientInterface
    {
        return new class($identity, $throw, $onAuthenticate) implements GoogleOAuthClientInterface {
            public function __construct(private ?GoogleIdentity $identity, private ?\Throwable $throw, private ?\Closure $onAuthenticate)
            {
            }

            public function authorizationUrl(string $state, ?string $codeVerifier = null): string
            {
                return 'https://accounts.google.com/o/oauth2/v2/auth?state='.$state;
            }

            public function authenticate(string $code, ?string $codeVerifier = null): GoogleIdentity
            {
                if (null !== $this->onAuthenticate) {
                    ($this->onAuthenticate)($code, $codeVerifier);
                }

                if (null !== $this->throw) {
                    throw $this->throw;
                }

                // The interface guarantees a non-null identity or a throw; a
                // fake given neither is a test-setup error.
                return $this->identity ?? throw new \LogicException('fake has no identity');
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function authenticator(?GoogleIdentity $identity, ?\Throwable $throw = null, array $options = []): GoogleAuthenticator
    {
        return new GoogleAuthenticator($this->client($identity, $throw), $this->userProvider, $this->session, $options);
    }

    private function callbackRequest(string $code = 'auth-code', string $state = 'the-state'): ServerRequest
    {
        return (new ServerRequest('GET', self::CALLBACK))
            ->withQueryParams(['code' => $code, 'state' => $state]);
    }

    private function withStoredState(string $state = 'the-state'): void
    {
        $this->session->set(self::STATE_KEY, $state);
    }

    public function testSupportsOnlyTheCallbackWithACode(): void
    {
        $auth = $this->authenticator(null);

        $this->assertTrue($auth->supports($this->callbackRequest()));
        $this->assertFalse($auth->supports(new ServerRequest('GET', self::CALLBACK))); // no code
        $this->assertFalse($auth->supports((new ServerRequest('GET', '/panel/login'))->withQueryParams(['code' => 'x'])));
    }

    public function testResolvesAnExistingUserByVerifiedEmail(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(new GoogleIdentity('sub-1', 'member@example.com', true));

        $user = $auth->authenticate($this->callbackRequest());

        $this->assertSame('member@example.com', $user->getUserIdentifier());

        $token = $auth->createToken($user, 'main');
        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
    }

    public function testRejectsAnUnknownEmail(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(new GoogleIdentity('sub-2', 'stranger@example.com', true));

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    public function testRejectsAnUnverifiedEmail(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(new GoogleIdentity('sub-3', 'member@example.com', false));

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    public function testRejectsAStateMismatch(): void
    {
        $this->session->set(self::STATE_KEY, 'a-different-state');
        $auth = $this->authenticator(new GoogleIdentity('sub-4', 'member@example.com', true));

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest(state: 'forged-state'));
    }

    public function testRejectsWhenNoStateWasStored(): void
    {
        // Nothing in the session — a callback arriving without us having
        // started the flow must not authenticate.
        $auth = $this->authenticator(new GoogleIdentity('sub-5', 'member@example.com', true));

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    public function testStateIsSingleUse(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(new GoogleIdentity('sub-6', 'member@example.com', true));

        $auth->authenticate($this->callbackRequest());

        // Second use of the same callback must fail: the state was consumed.
        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    public function testSurfacesAClientFailureAsAFailedLogin(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(null, new GoogleOAuthException('bad signature'));

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    public function testEnforcesAllowedHostedDomainWhenSet(): void
    {
        $this->withStoredState();
        $auth = $this->authenticator(
            new GoogleIdentity('sub-7', 'member@example.com', true, hostedDomain: 'other.com'),
            options: ['allowed_hosted_domain' => 'example.com'],
        );

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->callbackRequest());
    }

    /**
     * The PKCE verifier the start action stored is handed to the exchange
     * and removed from the session, exactly like the state.
     */
    public function testCodeVerifierIsPassedToTheClientAndConsumed(): void
    {
        $received = null;
        $client = $this->client(
            new GoogleIdentity('sub', 'member@example.com', true, null, null),
            onAuthenticate: static function (string $code, ?string $verifier) use (&$received): void {
                $received = $verifier;
            },
        );
        $authenticator = new GoogleAuthenticator($client, $this->userProvider, $this->session);
        $this->session->set('_google_oauth_state', 'the-state');
        $this->session->set('_google_oauth_code_verifier', 'the-verifier');

        $authenticator->authenticate($this->callbackRequest());

        $this->assertSame('the-verifier', $received);
        $this->assertFalse($this->session->has('_google_oauth_code_verifier'));
    }

    /**
     * Google vouches for the address, not for the second factor enabled here.
     */
    public function testUserWithTwoFactorEnabledIsSentToTheSecondFactor(): void
    {
        $secret = $this->createMock(TwoFactorSecret::class);
        $secret->method('isEnabled')->willReturn(true);
        $totp = $this->createMock(TwoFactorServiceInterface::class);
        $totp->method('getTwoFactorSecret')->willReturn($secret);

        $client = $this->client(new GoogleIdentity('sub', 'member@example.com', true, null, null));
        $authenticator = new GoogleAuthenticator($client, $this->userProvider, $this->session, [], $totp);
        $this->session->set('_google_oauth_state', 'the-state');

        try {
            $authenticator->authenticate($this->callbackRequest());
            $this->fail('Expected TwoFactorRequiredException.');
        } catch (TwoFactorRequiredException $e) {
            $this->assertSame('member@example.com', $e->getUser()->getUserIdentifier());
            $response = $authenticator->unauthorizedResponse($this->callbackRequest(), $e);
            $this->assertSame(303, $response->getStatusCode());
            $this->assertSame('/2fa', $response->getHeaderLine('Location'));
        }
    }
}
