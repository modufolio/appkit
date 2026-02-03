<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use Modufolio\Appkit\Security\Authenticator\FormLoginAuthenticator;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class FormLoginAuthenticatorTest extends TestCase
{
    private UserProviderInterface $userProvider;
    private BruteForceProtectionInterface $bruteForceProtection;
    private CsrfTokenManagerInterface $csrfTokenManager;
    private FlashBagAwareSessionInterface $session;
    private PasswordAuthenticatedUserInterface $user;
    private string $validPassword = 'secret123';
    private string $hashedPassword;

    protected function setUp(): void
    {
        parent::setUp();

        // Hash password for testing
        $this->hashedPassword = password_hash($this->validPassword, PASSWORD_DEFAULT);

        // Create mock user
        $this->user = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $this->user->method('getUserIdentifier')->willReturn('test@example.com');
        $this->user->method('getRoles')->willReturn(['ROLE_USER']);
        $this->user->method('getPassword')->willReturn($this->hashedPassword);

        // Create mock user provider
        $this->userProvider = $this->createMock(UserProviderInterface::class);

        // Create mock brute force protection (not locked by default)
        $this->bruteForceProtection = $this->createMock(BruteForceProtectionInterface::class);
        $this->bruteForceProtection->method('isLocked')->willReturn(false);

        // Create mock CSRF token manager (valid by default)
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->csrfTokenManager->method('validateToken')->willReturn(true);

        // Create mock session with flash bag using stub
        $flashBag = $this->createMock(FlashBagInterface::class);
        // Create anonymous class implementing FlashBagAwareSessionInterface
        $this->session = new class($flashBag) implements FlashBagAwareSessionInterface {
            private FlashBagInterface $flashBag;
            public function __construct(FlashBagInterface $flashBag) { $this->flashBag = $flashBag; }
            public function getFlashBag(): FlashBagInterface { return $this->flashBag; }
            public function start(): bool { return true; }
            public function getId(): string { return 'test-session-id'; }
            public function setId(string $id): void {}
            public function getName(): string { return 'test'; }
            public function setName(string $name): void {}
            public function invalidate(?int $lifetime = null): bool { return true; }
            public function migrate(bool $destroy = false, ?int $lifetime = null): bool { return true; }
            public function save(): void {}
            public function has(string $name): bool { return false; }
            public function get(string $name, mixed $default = null): mixed { return $default; }
            public function set(string $name, mixed $value): void {}
            public function all(): array { return []; }
            public function replace(array $attributes): void {}
            public function remove(string $name): mixed { return null; }
            public function clear(): void {}
            public function isStarted(): bool { return true; }
            public function registerBag(\Symfony\Component\HttpFoundation\Session\SessionBagInterface $bag): void {}
            public function getBag(string $name): \Symfony\Component\HttpFoundation\Session\SessionBagInterface { throw new \RuntimeException('Not implemented'); }
            public function getMetadataBag(): \Symfony\Component\HttpFoundation\Session\Storage\MetadataBag { throw new \RuntimeException('Not implemented'); }
        };
    }

    private function createAuthenticator(array $options = []): FormLoginAuthenticator
    {
        return new FormLoginAuthenticator(
            $this->userProvider,
            $this->bruteForceProtection,
            $this->csrfTokenManager,
            $this->session,
            null,
            null,
            $options
        );
    }

    public function testSupportsReturnsTrueForPostRequestToCheckPath(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseForGetRequest(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/login'),
            headers: []
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseForWrongPath(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/wrong-path'),
            headers: []
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsWorksWithCustomCheckPath(): void
    {
        $authenticator = $this->createAuthenticator([
            'check_path' => '/auth/login'
        ]);

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/auth/login'),
            headers: []
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsWorksWithCustomUsernameParameter(): void
    {
        $authenticator = $this->createAuthenticator([
            'username_parameter' => 'username'
        ]);

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionWhenUsernameIsMissing(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['password' => 'secret123', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUsernameIsEmpty(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => '', 'password' => 'secret123', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUsernameIsWhitespace(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => '   ', 'password' => 'secret123', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsMissing(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsEmpty(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'password' => '', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUserDoesNotSupportPasswordAuth(): void
    {
        $authenticator = $this->createAuthenticator();

        // Create a user that doesn't implement PasswordAuthenticatedUserInterface
        $nonPasswordUser = $this->createMock(UserInterface::class);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($nonPasswordUser);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'password' => 'secret123', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User does not support password authentication.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsInvalid(): void
    {
        $authenticator = $this->createAuthenticator();

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'password' => 'wrongpassword', '_csrf_token' => 'valid-token']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateSuccessfullyWithValidCredentials(): void
    {
        $authenticator = $this->createAuthenticator();

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'password' => $this->validPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateWorksWithCustomUsernameParameter(): void
    {
        $authenticator = $this->createAuthenticator([
            'username_parameter' => 'username'
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('john_doe')
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['username' => 'john_doe', 'password' => $this->validPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateWorksWithCustomPasswordParameter(): void
    {
        $authenticator = $this->createAuthenticator([
            'password_parameter' => 'pass'
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'pass' => $this->validPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateTrimsUsername(): void
    {
        $authenticator = $this->createAuthenticator();

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com') // Should be trimmed
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => '  test@example.com  ', 'password' => $this->validPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testCreateTokenReturnsUsernamePasswordToken(): void
    {
        $authenticator = $this->createAuthenticator();

        $token = $authenticator->createToken($this->user, 'main');

        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('main', $token->getFirewallName());
        $this->assertEquals(['ROLE_USER'], $token->getRoles());
    }

    public function testUnauthorizedResponseRedirectsToLogin(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/login')
        );

        $exception = new AuthenticationException('Invalid credentials');
        $response = $authenticator->unauthorizedResponse($request, $exception);

        // Form login redirects to login page on failure
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/login', $response->getHeaderLine('Location'));
    }

    public function testAuthenticateWithSpecialCharactersInPassword(): void
    {
        $authenticator = $this->createAuthenticator();

        $specialPassword = 'p@$$w0rd!#%&<>\'"/\\';
        $hashedSpecialPassword = password_hash($specialPassword, PASSWORD_DEFAULT);

        $specialUser = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $specialUser->method('getUserIdentifier')->willReturn('test@example.com');
        $specialUser->method('getRoles')->willReturn(['ROLE_USER']);
        $specialUser->method('getPassword')->willReturn($hashedSpecialPassword);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($specialUser);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'test@example.com', 'password' => $specialPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($specialUser, $result);
    }

    public function testAuthenticateHandlesNullParsedBody(): void
    {
        $authenticator = $this->createAuthenticator();

        $request = new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        );

        $this->expectException(AuthenticationException::class);

        $authenticator->authenticate($request);
    }

    public function testAuthenticateWithMultipleRoles(): void
    {
        $authenticator = $this->createAuthenticator();

        $multiRoleUser = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $multiRoleUser->method('getUserIdentifier')->willReturn('admin@example.com');
        $multiRoleUser->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $multiRoleUser->method('getPassword')->willReturn($this->hashedPassword);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('admin@example.com')
            ->willReturn($multiRoleUser);

        $request = (new ServerRequest(
            method: 'POST',
            uri: new Uri('/login'),
            headers: []
        ))->withParsedBody(['email' => 'admin@example.com', 'password' => $this->validPassword, '_csrf_token' => 'valid-token']);

        $result = $authenticator->authenticate($request);
        $token = $authenticator->createToken($result, 'main');

        $this->assertSame($multiRoleUser, $result);
        $this->assertEquals(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $token->getRoles());
    }
}
