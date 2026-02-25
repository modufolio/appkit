<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use Modufolio\Appkit\Security\Authenticator\BasicAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;

class BasicAuthenticatorTest extends AppTestCase
{
    private UserProviderInterface $userProvider;
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
    }

    public function testSupportsReturnsTrueWhenAuthorizationHeaderHasBasic(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $credentials = base64_encode('user@example.com:password123');
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenAuthorizationHeaderIsMissing(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: []
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenAuthorizationHeaderIsNotBasic(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Bearer token123']
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionWhenHeaderIsMissing(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: []
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing or invalid Authorization header.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenHeaderIsInvalidBase64(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic not-valid-base64!!!']
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid Basic authentication header.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenCredentialsMissingColon(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $credentials = base64_encode('userpassword');  // Missing colon
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid Basic authentication header.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUsernameIsEmpty(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $credentials = base64_encode(':password123');
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsEmpty(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $credentials = base64_encode('user@example.com:');
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUserDoesNotSupportPasswordAuth(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        // Create a user that doesn't implement PasswordAuthenticatedUserInterface
        $nonPasswordUser = $this->createMock(UserInterface::class);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($nonPasswordUser);

        $credentials = base64_encode('test@example.com:password123');
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User does not support password authentication.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenPasswordIsInvalid(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($this->user);

        $credentials = base64_encode('test@example.com:wrongpassword');
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateSuccessfullyWithValidCredentials(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($this->user);

        $credentials = base64_encode('test@example.com:' . $this->validPassword);
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateHandlesPasswordWithColon(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        // Password contains colon
        $passwordWithColon = 'pass:word:123';
        $hashedPasswordWithColon = password_hash($passwordWithColon, PASSWORD_DEFAULT);

        $userWithColonPassword = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $userWithColonPassword->method('getUserIdentifier')->willReturn('test@example.com');
        $userWithColonPassword->method('getRoles')->willReturn(['ROLE_USER']);
        $userWithColonPassword->method('getPassword')->willReturn($hashedPasswordWithColon);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('test@example.com')
            ->willReturn($userWithColonPassword);

        $credentials = base64_encode('test@example.com:' . $passwordWithColon);
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $result = $authenticator->authenticate($request);

        $this->assertSame($userWithColonPassword, $result);
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

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test')
        );

        $exception = new AuthenticationException('Invalid credentials');
        $response = $authenticator->unauthorizedResponse($request, $exception);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
        $this->assertEquals('Basic realm="Access to the API"', $response->getHeaderLine('WWW-Authenticate'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Invalid credentials', $body['error']);
    }

    public function testAuthenticateWithSpecialCharactersInCredentials(): void
    {
        $authenticator = new BasicAuthenticator($this->userProvider);

        $specialPassword = 'p@$$w0rd!#%&';
        $hashedSpecialPassword = password_hash($specialPassword, PASSWORD_DEFAULT);

        $specialUser = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $specialUser->method('getUserIdentifier')->willReturn('user+test@example.com');
        $specialUser->method('getRoles')->willReturn(['ROLE_USER']);
        $specialUser->method('getPassword')->willReturn($hashedSpecialPassword);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user+test@example.com')
            ->willReturn($specialUser);

        $credentials = base64_encode('user+test@example.com:' . $specialPassword);
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Basic ' . $credentials]
        );

        $result = $authenticator->authenticate($request);

        $this->assertSame($specialUser, $result);
    }
}
