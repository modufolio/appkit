<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Appkit\Security\Authenticator\OAuthAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\OAuthToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\InMemoryOAuthService;
use Modufolio\Appkit\Tests\App\TestLogger;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

class OAuthAuthenticatorTest extends AppTestCase
{
    private InMemoryOAuthService $oauthService;
    private InMemoryUser $user;
    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new InMemoryUser('oauth-user@example.com', null, ['ROLE_USER']);
        $this->oauthService = new InMemoryOAuthService();
        $this->logger = new TestLogger();
    }

    private function createAuthenticator(array $options = []): OAuthAuthenticator
    {
        return new OAuthAuthenticator(
            $this->oauthService,
            $options,
            $this->logger,
        );
    }

    private function bearerRequest(string $token, string $ip = '127.0.0.1'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/resource'),
            headers: ['Authorization' => 'Bearer ' . $token],
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    /**
     * Create an access token via the in-memory service and return the plain token string.
     */
    private function issueAccessToken(array $scopes = ['read'], string $clientId = 'test-client'): string
    {
        $tokenEntity = $this->oauthService->createAccessToken(
            $this->user,
            $clientId,
            'client_credentials',
            $scopes,
        );

        return $tokenEntity->getPlainAccessToken();
    }

    // ---- Constructor ----

    public function testConstructorSetsDefaultOptions(): void
    {
        $authenticator = $this->createAuthenticator();
        $this->assertInstanceOf(OAuthAuthenticator::class, $authenticator);
    }

    public function testConstructorUsesNullLoggerWhenNotProvided(): void
    {
        $authenticator = new OAuthAuthenticator($this->oauthService);
        $this->assertInstanceOf(OAuthAuthenticator::class, $authenticator);
    }

    // ---- supports() ----

    public function testSupportsReturnsTrueWithBearerToken(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => 'Bearer tok']);

        $this->assertTrue($auth->supports($request));
    }

    public function testSupportsReturnsFalseWithoutAuthorizationHeader(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: []);

        $this->assertFalse($auth->supports($request));
    }

    public function testSupportsReturnsFalseWithBasicScheme(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => 'Basic abc']);

        $this->assertFalse($auth->supports($request));
    }

    public function testSupportsReturnsFalseWithEmptyHeader(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => '']);

        $this->assertFalse($auth->supports($request));
    }

    public function testSupportsWithCustomTokenPrefix(): void
    {
        $auth = $this->createAuthenticator(['token_prefix' => 'Token']);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => 'Token tok']);

        $this->assertTrue($auth->supports($request));
    }

    public function testSupportsWithCustomHeaderName(): void
    {
        $auth = $this->createAuthenticator(['header_name' => 'X-OAuth']);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['X-OAuth' => 'Bearer tok']);

        $this->assertTrue($auth->supports($request));
    }

    // ---- authenticate() – success ----

    public function testAuthenticateReturnsUserOnValidToken(): void
    {
        $plainToken = $this->issueAccessToken();
        $auth = $this->createAuthenticator();

        $result = $auth->authenticate($this->bearerRequest($plainToken));

        $this->assertSame('oauth-user@example.com', $result->getUserIdentifier());
        $this->assertTrue($this->logger->hasInfo('Successful OAuth authentication'));
    }

    public function testAuthenticateLogsClientIdAndScopes(): void
    {
        $plainToken = $this->issueAccessToken(['read', 'write'], 'my-client');
        $auth = $this->createAuthenticator();

        $auth->authenticate($this->bearerRequest($plainToken));

        $infoRecord = null;
        foreach ($this->logger->records as $record) {
            if ($record['level'] === 'info' && str_contains($record['message'], 'Successful OAuth')) {
                $infoRecord = $record;
                break;
            }
        }

        $this->assertNotNull($infoRecord);
        $this->assertSame('my-client', $infoRecord['context']['client_id']);
        $this->assertSame(['read', 'write'], $infoRecord['context']['scopes']);
    }

    // ---- authenticate() – failures ----

    public function testAuthenticateThrowsOnInvalidToken(): void
    {
        $auth = $this->createAuthenticator();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired access token.');

        $auth->authenticate($this->bearerRequest('non-existent-token'));
    }

    public function testAuthenticateLogsWarningOnInvalidToken(): void
    {
        $auth = $this->createAuthenticator();

        try {
            $auth->authenticate($this->bearerRequest('bad-token'));
        } catch (AuthenticationException) {
        }

        $this->assertTrue($this->logger->hasWarning('Invalid or expired token'));
    }

    public function testAuthenticateThrowsOnRevokedToken(): void
    {
        $plainToken = $this->issueAccessToken();
        $this->oauthService->revokeAccessToken($plainToken);

        $auth = $this->createAuthenticator();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired access token.');

        $auth->authenticate($this->bearerRequest($plainToken));
    }

    public function testAuthenticateThrowsOnEmptyToken(): void
    {
        $auth = $this->createAuthenticator();

        // "Bearer " with trailing space gets trimmed by PSR-7, so
        // we exercise extractToken() with a whitespace-only token instead.
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api'),
            headers: ['Authorization' => 'Bearer  '],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $this->expectException(AuthenticationException::class);

        $auth->authenticate($request);
    }

    public function testAuthenticateThrowsOnMissingHeader(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api'),
            headers: [],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing or invalid Authorization header.');

        $auth->authenticate($request);
    }

    public function testAuthenticateLogsErrorOnServiceException(): void
    {
        $this->oauthService->throwOnValidate(
            new \RuntimeException('Database connection lost'),
        );

        $auth = $this->createAuthenticator();

        try {
            $auth->authenticate($this->bearerRequest('any-token'));
        } catch (AuthenticationException) {
        }

        $this->assertTrue($this->logger->hasError('OAuth authentication error'));
    }

    // ---- createToken() ----

    public function testCreateTokenReturnsOAuthToken(): void
    {
        $auth = $this->createAuthenticator();
        $token = $auth->createToken($this->user, 'api');

        $this->assertInstanceOf(OAuthToken::class, $token);
        $this->assertSame('oauth-user@example.com', $token->getUser()->getUserIdentifier());
        $this->assertSame('api', $token->getFirewallName());
        $this->assertSame(['ROLE_USER'], $token->getRoles());
    }

    // ---- unauthorizedResponse() ----

    public function testUnauthorizedResponseReturns401WithJson(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'));
        $exception = new AuthenticationException('Invalid token');

        $response = $auth->unauthorizedResponse($request, $exception);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertStringContainsString('realm="Access to the API"', $response->getHeaderLine('WWW-Authenticate'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Invalid token', $body['error']);
    }

    public function testUnauthorizedResponseRespectsCustomPrefix(): void
    {
        $auth = $this->createAuthenticator(['token_prefix' => 'Token']);
        $response = $auth->unauthorizedResponse(
            new ServerRequest(method: 'GET', uri: new Uri('/api')),
            new AuthenticationException('Denied'),
        );

        $this->assertStringContainsString('Token', $response->getHeaderLine('WWW-Authenticate'));
    }
}
