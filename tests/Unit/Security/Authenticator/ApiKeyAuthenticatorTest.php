<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use Modufolio\Appkit\Security\Authenticator\ApiKeyAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use PHPUnit\Framework\TestCase;

class ApiKeyAuthenticatorTest extends TestCase
{
    private UserProviderInterface $userProvider;
    private PasswordAuthenticatedUserInterface $user;
    private array $apiKeys = [
        'valid-api-key-123' => 'user1@example.com',
        'another-valid-key' => 'user2@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock user
        $this->user = $this->createMock(PasswordAuthenticatedUserInterface::class);
        $this->user->method('getUserIdentifier')->willReturn('user1@example.com');
        $this->user->method('getRoles')->willReturn(['ROLE_USER']);

        // Create mock user provider
        $this->userProvider = $this->createMock(UserProviderInterface::class);
    }

    public function testConstructorThrowsExceptionWhenApiKeysAreEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API keys must be configured.');

        new ApiKeyAuthenticator($this->userProvider, ['api_keys' => []]);
    }

    public function testConstructorThrowsExceptionWhenApiKeysAreMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API keys must be configured.');

        new ApiKeyAuthenticator($this->userProvider, []);
    }

    public function testConstructorSetsDefaultOptions(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $this->assertInstanceOf(ApiKeyAuthenticator::class, $authenticator);
    }

    public function testSupportsReturnsTrueWhenApiKeyHeaderExists(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => 'valid-api-key-123']
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenApiKeyHeaderIsMissing(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: []
        );

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWhenApiKeyInQueryParameter(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys,
            'query_parameter' => 'api_key'
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test?api_key=valid-api-key-123'),
            headers: []
        ))->withQueryParams(['api_key' => 'valid-api-key-123']);

        $this->assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenQueryParameterNotConfigured(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test?api_key=valid-api-key-123'),
            headers: []
        ))->withQueryParams(['api_key' => 'valid-api-key-123']);

        $this->assertFalse($authenticator->supports($request));
    }

    public function testSupportsWorksWithCustomHeaderName(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys,
            'header_name' => 'X-Custom-API-Key'
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-Custom-API-Key' => 'valid-api-key-123']
        );

        $this->assertTrue($authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionWhenApiKeyIsMissing(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: []
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing API key.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenApiKeyIsEmpty(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => '']
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing API key.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenApiKeyIsInvalid(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => 'invalid-key']
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenUserNotFound(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user1@example.com')
            ->willThrowException(new \Exception('User not found'));

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => 'valid-api-key-123']
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User not found for API key.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateSuccessfullyWithHeader(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user1@example.com')
            ->willReturn($this->user);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => 'valid-api-key-123']
        );

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateSuccessfullyWithQueryParameter(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys,
            'query_parameter' => 'api_key'
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user2@example.com')
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test?api_key=another-valid-key'),
            headers: []
        ))->withQueryParams(['api_key' => 'another-valid-key']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticatePrefersHeaderOverQueryParameter(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys,
            'query_parameter' => 'api_key'
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user1@example.com') // Should use header key
            ->willReturn($this->user);

        $request = (new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test?api_key=another-valid-key'),
            headers: ['X-API-KEY' => 'valid-api-key-123']
        ))->withQueryParams(['api_key' => 'another-valid-key']);

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testAuthenticateTrimsApiKey(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $this->userProvider->expects($this->once())
            ->method('loadUserByIdentifier')
            ->with('user1@example.com')
            ->willReturn($this->user);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['X-API-KEY' => '  valid-api-key-123  ']
        );

        $result = $authenticator->authenticate($request);

        $this->assertSame($this->user, $result);
    }

    public function testCreateTokenReturnsApiKeyToken(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $token = $authenticator->createToken($this->user, 'main');

        $this->assertInstanceOf(ApiKeyToken::class, $token);
        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('main', $token->getFirewallName());
        $this->assertEquals(['ROLE_USER'], $token->getRoles());
    }

    public function testUnauthorizedResponseReturns401WithWWWAuthenticate(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test')
        );

        $exception = new AuthenticationException('Invalid API key');
        $response = $authenticator->unauthorizedResponse($request, $exception);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
        $this->assertStringContainsString('X-API-KEY', $response->getHeaderLine('WWW-Authenticate'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Invalid API key', $body['error']);
    }

    public function testUnauthorizedResponseUsesCustomHeaderName(): void
    {
        $authenticator = new ApiKeyAuthenticator($this->userProvider, [
            'api_keys' => $this->apiKeys,
            'header_name' => 'X-Custom-Key'
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test')
        );

        $exception = new AuthenticationException('Invalid API key');
        $response = $authenticator->unauthorizedResponse($request, $exception);

        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
        $this->assertStringContainsString('X-Custom-Key', $response->getHeaderLine('WWW-Authenticate'));
    }
}
