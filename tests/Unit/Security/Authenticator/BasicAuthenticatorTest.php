<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Appkit\Security\Authenticator\BasicAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\PasswordUpgraderInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasher;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\App\InMemoryUserProvider;
use Modufolio\Appkit\Tests\App\NonPasswordUser;
use Modufolio\Appkit\Tests\App\TestBruteForceProtection;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

class BasicAuthenticatorTest extends AppTestCase
{
    private InMemoryUserProvider $userProvider;
    private InMemoryUser $user;
    private string $validPassword = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();

        // Real in-memory provider + user (hash stored as the password), so the
        // authenticator runs its real lookup and password verification.
        $this->user = new InMemoryUser(
            'test@example.com',
            password_hash($this->validPassword, PASSWORD_DEFAULT),
            ['ROLE_USER'],
        );

        $this->userProvider = (new InMemoryUserProvider())->addUser($this->user);
    }

    private function basicRequest(string $rawCredentials, bool $encode = true): ServerRequest
    {
        $value = $encode ? base64_encode($rawCredentials) : $rawCredentials;

        return new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic '.$value],
        );
    }

    public function testSupportsReturnsTrueWhenAuthorizationHeaderHasBasic(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->assertTrue($authenticator->supports($this->basicRequest('user@example.com:password123')));
    }

    public function testSupportsReturnsFalseWhenAuthorizationHeaderIsMissing(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/api/test'), headers: []);

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenAuthorizationHeaderIsNotBasic(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Bearer token123'],
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionWhenHeaderIsMissing(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/api/test'), headers: []);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing or invalid Authorization header.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenHeaderIsInvalidBase64(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid Basic authentication header.');

        $authenticator->authenticate($this->basicRequest('not-valid-base64!!!', encode: false));
    }

    public function testAuthenticateThrowsExceptionWhenCredentialsMissingColon(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid Basic authentication header.');

        $authenticator->authenticate($this->basicRequest('userpassword'));
    }

    public function testAuthenticateThrowsExceptionWhenUsernameIsEmpty(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($this->basicRequest(':password123'));
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsEmpty(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($this->basicRequest('user@example.com:'));
    }

    public function testAuthenticateThrowsExceptionWhenUserDoesNotSupportPasswordAuth(): void
    {
        // Real provider returning a user that has no password support.
        $provider = new class implements UserProviderInterface {
            public function loadUserByIdentifier(string $identifier): UserInterface
            {
                return new NonPasswordUser($identifier);
            }

            public function refreshUser(UserInterface $user): UserInterface
            {
                return $user;
            }

            public function supportsClass(string $class): bool
            {
                return true;
            }
        };

        $authenticator = new BasicAuthenticator($provider);

        // Generic message: must not reveal that the account exists.
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate($this->basicRequest('test@example.com:password123'));
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsInvalid(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate($this->basicRequest('test@example.com:wrongpassword'));
    }

    public function testAuthenticateSuccessfullyWithValidCredentials(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $result = $authenticator->authenticate($this->basicRequest('test@example.com:'.$this->validPassword));

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateHandlesPasswordWithColon(): void
    {
        $passwordWithColon = 'pass:word:123';
        $this->userProvider->addUser(new InMemoryUser(
            'colon@example.com',
            password_hash($passwordWithColon, PASSWORD_DEFAULT),
            ['ROLE_USER'],
        ));

        $authenticator = new BasicAuthenticator($this->userProvider);

        $result = $authenticator->authenticate($this->basicRequest('colon@example.com:'.$passwordWithColon));

        $this->assertSame('colon@example.com', $result->getUserIdentifier());
    }

    public function testSuccessfulLoginUpgradesOutdatedHash(): void
    {
        // Stored hash uses a low bcrypt cost; the hasher targets a higher cost,
        // so a successful login should trigger a transparent rehash.
        $weakHash = password_hash($this->validPassword, PASSWORD_BCRYPT, ['cost' => 5]);
        $hasher = new UserPasswordHasher(['algo' => PASSWORD_BCRYPT, 'options' => ['cost' => 12]]);

        $user = new InMemoryUser('weak@example.com', $weakHash, ['ROLE_USER']);

        // Real provider that also upgrades passwords and records the new hash.
        $provider = new class($user) implements UserProviderInterface, PasswordUpgraderInterface {
            public ?string $upgradedTo = null;

            public function __construct(private InMemoryUser $user)
            {
            }

            public function loadUserByIdentifier(string $identifier): UserInterface
            {
                return $this->user;
            }

            public function refreshUser(UserInterface $user): UserInterface
            {
                return $user;
            }

            public function supportsClass(string $class): bool
            {
                return true;
            }

            public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
            {
                $this->upgradedTo = $newHashedPassword;
            }
        };

        $authenticator = new BasicAuthenticator($provider, $hasher);

        $result = $authenticator->authenticate($this->basicRequest('weak@example.com:'.$this->validPassword));

        $this->assertSame($user, $result);
        $this->assertNotNull($provider->upgradedTo, 'Expected the outdated hash to be upgraded.');
        $this->assertTrue(password_verify($this->validPassword, $provider->upgradedTo));
    }

    public function testLockedAccountIsRejectedBeforePasswordCheck(): void
    {
        $bruteForce = new TestBruteForceProtection();
        // No REMOTE_ADDR in the request, so the lock key uses a null IP.
        $bruteForce->forceLock('test@example.com', null);

        $authenticator = new BasicAuthenticator($this->userProvider, null, $bruteForce);

        // Credentials are valid; only the lock can produce this failure, proving
        // the lock is checked before the password.
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Too many failed login attempts. Try again later.');

        $authenticator->authenticate($this->basicRequest('test@example.com:'.$this->validPassword));
    }

    public function testFailedLoginRecordsBruteForceFailure(): void
    {
        $bruteForce = new TestBruteForceProtection();
        $authenticator = new BasicAuthenticator($this->userProvider, null, $bruteForce);

        try {
            $authenticator->authenticate($this->basicRequest('test@example.com:wrongpassword'));
            $this->fail('Expected authentication to fail.');
        } catch (AuthenticationException) {
            // expected
        }

        $this->assertSame(1, $bruteForce->getFailureCount('test@example.com', null));
    }

    public function testSuccessfulLoginResetsBruteForceCounter(): void
    {
        $bruteForce = new TestBruteForceProtection();
        $bruteForce->recordFailure('test@example.com', null);
        $authenticator = new BasicAuthenticator($this->userProvider, null, $bruteForce);

        $authenticator->authenticate($this->basicRequest('test@example.com:'.$this->validPassword));

        $this->assertSame(0, $bruteForce->getFailureCount('test@example.com', null));
    }

    public function testCreateTokenReturnsUsernamePasswordToken(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $token = $authenticator->createToken($this->user, 'main');

        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('main', $token->getFirewallName());
        $this->assertEquals(['ROLE_USER'], $token->getRoles());
    }

    public function testUnauthorizedResponseReturns401WithWWWAuthenticate(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/api/test'));

        $response = $authenticator->unauthorizedResponse($request, new AuthenticationException('Invalid credentials'));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
        $this->assertEquals('Basic realm="Access to the API"', $response->getHeaderLine('WWW-Authenticate'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Invalid credentials.', $body['error']);
    }

    public function testAuthenticateWithSpecialCharactersInCredentials(): void
    {
        $specialPassword = 'p@$$w0rd!#%&';
        $this->userProvider->addUser(new InMemoryUser(
            'user+test@example.com',
            password_hash($specialPassword, PASSWORD_DEFAULT),
            ['ROLE_USER'],
        ));

        $authenticator = new BasicAuthenticator($this->userProvider);

        $result = $authenticator->authenticate($this->basicRequest('user+test@example.com:'.$specialPassword));

        $this->assertSame('user+test@example.com', $result->getUserIdentifier());
    }
}
