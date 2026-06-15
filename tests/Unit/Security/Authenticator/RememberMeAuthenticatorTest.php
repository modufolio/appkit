<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\InMemoryUserProvider;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

class RememberMeAuthenticatorTest extends AppTestCase
{
    private InMemoryUserProvider $userProvider;
    private InMemoryUser $user;
    private string $secret = 'test-secret-key-12345';
    private string $passwordHash = '$2y$10$abcdefghijklmnopqrstuv';

    protected function setUp(): void
    {
        parent::setUp();

        // Real in-memory user + provider (password hash stored as the password).
        $this->user = new InMemoryUser('test@example.com', $this->passwordHash, ['ROLE_USER']);
        $this->userProvider = (new InMemoryUserProvider())->addUser($this->user);
    }

    private function fingerprint(?string $password): string
    {
        return null === $password || '' === $password ? '' : hash('sha256', $password);
    }

    private function signCookie(string $identifier, int $expires, ?string $password): string
    {
        $hash = hash_hmac(
            'sha256',
            sprintf('%s:%d:%s', $identifier, $expires, $this->fingerprint($password)),
            $this->secret,
        );

        return base64_encode(sprintf('%s:%d:%s', $identifier, $expires, $hash));
    }

    public function testConstructorThrowsExceptionWhenSecretIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RememberMe secret must be configured.');

        new RememberMeAuthenticator($this->userProvider, ['secret' => '']);
    }

    public function testConstructorSetsDefaultOptions(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $this->assertInstanceOf(RememberMeAuthenticator::class, $authenticator);
    }

    public function testSupportsReturnsTrueWhenCookieExists(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => 'test-cookie-value']);

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenCookieIsMissing(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsWorksWithCustomCookieName(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
            'cookie_name' => 'CUSTOM_REMEMBER',
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['CUSTOM_REMEMBER' => 'test-cookie-value']);

        $this->assertTrue($authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionWhenCookieIsEmpty(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => '']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Remember me cookie is empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenCookieIsNotBase64(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => 'not-valid-base64!!!']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid remember me cookie format.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenCookieStructureIsInvalid(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        // Only two parts instead of three
        $invalidData = base64_encode('test@example.com:12345');

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $invalidData]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid remember me cookie structure.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenCookieHasExpired(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $identifier = 'test@example.com';
        $expires = time() - 3600; // Expired 1 hour ago
        $cookieValue = $this->signCookie($identifier, $expires, '$2y$10$abcdefghijklmnopqrstuv');

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Remember me cookie has expired.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenSignatureIsInvalid(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $identifier = 'test@example.com';
        $expires = time() + 3600;
        $invalidHash = 'invalid-hash-value';

        $cookieData = sprintf('%s:%d:%s', $identifier, $expires, $invalidHash);
        $cookieValue = base64_encode($cookieData);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid remember me cookie signature.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUserNotFound(): void
    {
        // Empty provider: the cookie identifier resolves to no user.
        $authenticator = new RememberMeAuthenticator(new InMemoryUserProvider(), [
            'secret' => $this->secret,
        ]);

        $identifier = 'test@example.com';
        $expires = time() + 3600;
        $cookieValue = $this->signCookie($identifier, $expires, $this->passwordHash);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User not found for remember me cookie.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateSuccessfully(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $identifier = 'test@example.com';
        $expires = time() + 3600;
        $cookieValue = $this->signCookie($identifier, $expires, $this->passwordHash);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testCreateTokenReturnsRememberMeToken(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $token = $authenticator->createToken($this->user, 'main');

        $this->assertInstanceOf(RememberMeToken::class, $token);
        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('main', $token->getFirewallName());
        $this->assertEquals($this->secret, $token->getSecret());
        $this->assertEquals(['ROLE_USER'], $token->getRoles());
    }

    public function testUnauthorizedResponseReturns401(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/')
        );

        $exception = new AuthenticationException('Invalid remember me cookie');
        $response = $authenticator->unauthorizedResponse($request, $exception);

        $this->assertEquals(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_token', $body['error']);
    }

    public function testGenerateRememberMeCookieCreatesValidCookie(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $cookieValue = $authenticator->generateRememberMeCookie($this->user);

        $this->assertNotEmpty($cookieValue);

        // Decode and verify cookie structure
        $cookieData = base64_decode($cookieValue, true);
        $this->assertNotFalse($cookieData);

        $parts = explode(':', $cookieData, 3);
        $this->assertCount(3, $parts);

        [$identifier, $expires, $hash] = $parts;

        $this->assertEquals('test@example.com', $identifier);
        $this->assertGreaterThan(time(), (int) $expires);

        // Verify hash binds the user's password fingerprint so a password
        // change invalidates outstanding cookies.
        $expectedHash = hash_hmac(
            'sha256',
            sprintf('%s:%d:%s', $identifier, (int) $expires, $this->fingerprint('$2y$10$abcdefghijklmnopqrstuv')),
            $this->secret,
        );
        $this->assertEquals($expectedHash, $hash);
    }

    public function testCookieIsInvalidatedWhenUserPasswordChanges(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $cookieValue = $authenticator->generateRememberMeCookie($this->user);

        // The provider now returns a user whose password hash has changed, so the
        // cookie's fingerprint no longer matches.
        $rotatedUser = new InMemoryUser('test@example.com', '$2y$10$DIFFERENT_HASH_AFTER_ROTATION', ['ROLE_USER']);
        $rotatedProvider = (new InMemoryUserProvider())->addUser($rotatedUser);
        $authenticator = new RememberMeAuthenticator($rotatedProvider, ['secret' => $this->secret]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid remember me cookie signature.');

        $authenticator->authenticate($request);
    }

    public function testGenerateRememberMeCookieWithCustomLifetime(): void
    {
        $lifetime = 86400; // 1 day
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
            'cookie_lifetime' => $lifetime,
        ]);

        $before = time() + $lifetime;
        $cookieValue = $authenticator->generateRememberMeCookie($this->user);
        $after = time() + $lifetime;

        $cookieData = base64_decode($cookieValue, true);
        $parts = explode(':', $cookieData, 3);
        $expires = (int) $parts[1];

        $this->assertGreaterThanOrEqual($before, $expires);
        $this->assertLessThanOrEqual($after, $expires);
    }

    public function testGetCookieOptionsReturnsCorrectOptions(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
            'cookie_lifetime' => 3600,
            'cookie_path' => '/app',
            'cookie_domain' => 'example.com',
            'cookie_secure' => false,
            'cookie_httponly' => false,
            'cookie_samesite' => 'Strict',
        ]);

        $options = $authenticator->getCookieOptions();

        $this->assertGreaterThan(time(), $options['expires']);
        $this->assertEquals('/app', $options['path']);
        $this->assertEquals('example.com', $options['domain']);
        $this->assertFalse($options['secure']);
        $this->assertFalse($options['httponly']);
        $this->assertEquals('Strict', $options['samesite']);
    }

    public function testGetCookieOptionsWithDefaultValues(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $options = $authenticator->getCookieOptions();

        $this->assertArrayHasKey('expires', $options);
        $this->assertEquals('/', $options['path']);
        $this->assertNull($options['domain']);
        $this->assertTrue($options['secure']);
        $this->assertTrue($options['httponly']);
        $this->assertEquals('Lax', $options['samesite']);
    }

    public function testGetCookieNameReturnsConfiguredName(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        $this->assertEquals('REMEMBERME', $authenticator->getCookieName());
    }

    public function testGetCookieNameReturnsCustomName(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
            'cookie_name' => 'CUSTOM_COOKIE',
        ]);

        $this->assertEquals('CUSTOM_COOKIE', $authenticator->getCookieName());
    }

    public function testGeneratedCookieCanBeAuthenticated(): void
    {
        $authenticator = new RememberMeAuthenticator($this->userProvider, [
            'secret' => $this->secret,
        ]);

        // Generate cookie for user
        $cookieValue = $authenticator->generateRememberMeCookie($this->user);

        // Create request with the generated cookie
        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/'),
            headers: []
        ))->withCookieParams(['REMEMBERME' => $cookieValue]);

        // Authenticate should succeed
        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }
}
