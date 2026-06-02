<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modufolio\Appkit\Security\Authenticator\JwtAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\InMemoryUserProvider;
use Modufolio\Appkit\Tests\App\TestLogger;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

class JwtAuthenticatorTest extends AppTestCase
{
    private InMemoryUserProvider $userProvider;
    private TestLogger $logger;
    private string $secretKey = 'test-secret-key-for-jwt-testing-must-be-long-enough-for-hs256';

    protected function setUp(): void
    {
        parent::setUp();

        $this->userProvider = new InMemoryUserProvider();
        $this->userProvider->addUser(new InMemoryUser('user@example.com', 'password', ['ROLE_USER']));
        $this->userProvider->addUser(new InMemoryUser('admin@example.com', 'password', ['ROLE_ADMIN']));

        $this->logger = new TestLogger();
    }

    private function createAuthenticator(array $options = []): JwtAuthenticator
    {
        return new JwtAuthenticator(
            $this->userProvider,
            array_merge(['secret_key' => $this->secretKey], $options),
            $this->logger,
        );
    }

    private function generateToken(array $claims = [], ?int $expiresIn = 3600, ?string $key = null, string $algo = 'HS256'): string
    {
        $now = time();
        $payload = array_merge([
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'sub' => 'user@example.com',
        ], $claims);

        return JWT::encode($payload, $key ?? $this->secretKey, $algo);
    }

    private function bearerRequest(string $token, string $ip = '127.0.0.1'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Bearer '.$token],
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    // ---- Constructor ----

    public function testConstructorThrowsWhenSecretKeyIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT verification_key must be configured');

        new JwtAuthenticator($this->userProvider, ['secret_key' => '']);
    }

    public function testConstructorThrowsForUnsupportedAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported JWT algorithm');

        new JwtAuthenticator($this->userProvider, [
            'secret_key' => $this->secretKey,
            'algorithm' => 'none',
        ]);
    }

    public function testConstructorDefaultsToNullLoggerWhenNoneProvided(): void
    {
        $auth = new JwtAuthenticator($this->userProvider, ['secret_key' => $this->secretKey]);
        $this->assertInstanceOf(JwtAuthenticator::class, $auth);
    }

    // ---- supports() ----

    public function testSupportsReturnsTrueWithBearerHeader(): void
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

    public function testSupportsReturnsFalseForBasicScheme(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => 'Basic abc']);

        $this->assertFalse($auth->supports($request));
    }

    public function testSupportsReturnsFalseForEmptyHeader(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => '']);

        $this->assertFalse($auth->supports($request));
    }

    public function testSupportsWithCustomPrefix(): void
    {
        $auth = $this->createAuthenticator(['token_prefix' => 'Token']);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['Authorization' => 'Token tok']);

        $this->assertTrue($auth->supports($request));
    }

    public function testSupportsWithCustomHeaderName(): void
    {
        $auth = $this->createAuthenticator(['header_name' => 'X-Auth']);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api'), headers: ['X-Auth' => 'Bearer tok']);

        $this->assertTrue($auth->supports($request));
    }

    // ---- authenticate() – success ----

    public function testAuthenticateReturnsUserOnValidToken(): void
    {
        $auth = $this->createAuthenticator();
        $token = $this->generateToken();
        $user = $auth->authenticate($this->bearerRequest($token));

        $this->assertSame('user@example.com', $user->getUserIdentifier());
        $this->assertTrue($this->logger->hasInfo('Successful JWT authentication'));
    }

    public function testAuthenticateWithCustomIdentifierClaim(): void
    {
        $auth = $this->createAuthenticator(['user_identifier_claim' => 'email']);
        $token = $this->generateToken(['email' => 'admin@example.com']);

        $user = $auth->authenticate($this->bearerRequest($token));
        $this->assertSame('admin@example.com', $user->getUserIdentifier());
    }

    // ---- authenticate() – failures ----

    public function testAuthenticateThrowsOnMissingSubClaim(): void
    {
        $auth = $this->createAuthenticator();
        $now = time();
        $token = JWT::encode(['iat' => $now, 'exp' => $now + 3600, 'name' => 'test'], $this->secretKey, 'HS256');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT payload missing "sub" claim.');

        $auth->authenticate($this->bearerRequest($token));
    }

    public function testAuthenticateLogsWarningOnMissingClaim(): void
    {
        $auth = $this->createAuthenticator();
        $now = time();
        $token = JWT::encode(['iat' => $now, 'exp' => $now + 3600], $this->secretKey, 'HS256');

        try {
            $auth->authenticate($this->bearerRequest($token));
        } catch (AuthenticationException) {
        }

        $this->assertTrue($this->logger->hasWarning('Missing user identifier claim'));
    }

    public function testAuthenticateThrowsWhenUserNotFound(): void
    {
        $auth = $this->createAuthenticator();
        $token = $this->generateToken(['sub' => 'unknown@example.com']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User not found for JWT token.');

        $auth->authenticate($this->bearerRequest($token));
    }

    public function testAuthenticateLogsWhenUserNotFound(): void
    {
        $auth = $this->createAuthenticator();
        $token = $this->generateToken(['sub' => 'ghost@example.com']);

        try {
            $auth->authenticate($this->bearerRequest($token));
        } catch (AuthenticationException) {
        }

        $this->assertTrue($this->logger->hasWarning('User not found'));
    }

    public function testAuthenticateThrowsOnExpiredToken(): void
    {
        $auth = $this->createAuthenticator();
        $token = $this->generateToken(expiresIn: -3600);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT token has expired.');

        $auth->authenticate($this->bearerRequest($token));
    }

    public function testAuthenticateThrowsOnInvalidSignature(): void
    {
        $auth = $this->createAuthenticator();
        $token = $this->generateToken(key: str_repeat('x', 64));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT token signature is invalid.');

        $auth->authenticate($this->bearerRequest($token));
    }

    public function testAuthenticateThrowsOnNotYetValidToken(): void
    {
        $auth = $this->createAuthenticator();
        $now = time();
        $payload = ['iat' => $now, 'nbf' => $now + 7200, 'exp' => $now + 14400, 'sub' => 'user@example.com'];
        $token = JWT::encode($payload, $this->secretKey, 'HS256');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT token is not yet valid.');

        $auth->authenticate($this->bearerRequest($token));
    }

    public function testAuthenticateThrowsOnMalformedToken(): void
    {
        $auth = $this->createAuthenticator();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid JWT token');

        $auth->authenticate($this->bearerRequest('not.a.valid.jwt'));
    }

    public function testAuthenticateLogsErrorOnMalformedToken(): void
    {
        $auth = $this->createAuthenticator();

        try {
            $auth->authenticate($this->bearerRequest('garbage'));
        } catch (AuthenticationException) {
        }

        $this->assertTrue($this->logger->hasError('JWT authentication error'));
    }

    public function testAuthenticateThrowsOnEmptyToken(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(
            method: 'GET',
            uri: new Uri('/api/test'),
            headers: ['Authorization' => 'Bearer '],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $this->expectException(AuthenticationException::class);

        $auth->authenticate($request);
    }

    // ---- createToken() ----

    public function testCreateTokenReturnsJwtToken(): void
    {
        $auth = $this->createAuthenticator();
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $token = $auth->createToken($user, 'api');

        $this->assertInstanceOf(JwtToken::class, $token);
        $this->assertSame('user@example.com', $token->getUser()->getUserIdentifier());
        $this->assertSame('api', $token->getFirewallName());
        $this->assertSame(['ROLE_USER'], $token->getRoles());
        $this->assertSame([], $token->getPayload());
    }

    public function testCreateTokenCarriesPayloadFromAuthenticate(): void
    {
        $auth = $this->createAuthenticator();
        $jwt = $this->generateToken(['custom' => 'value']);

        $user = $auth->authenticate($this->bearerRequest($jwt));
        $token = $auth->createToken($user, 'api');

        $this->assertInstanceOf(JwtToken::class, $token);
        $this->assertSame('value', $token->getPayload()['custom']);
        $this->assertSame('user@example.com', $token->getPayload()['sub']);
    }

    // ---- unauthorizedResponse() ----

    public function testUnauthorizedResponseReturns401WithJsonBody(): void
    {
        $auth = $this->createAuthenticator();
        $request = new ServerRequest(method: 'GET', uri: new Uri('/api/test'));
        $exception = new AuthenticationException('Invalid token');

        $response = $auth->unauthorizedResponse($request, $exception);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertStringContainsString('realm="Access to the API"', $response->getHeaderLine('WWW-Authenticate'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_token', $body['error']);
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

    // ---- generateToken() ----

    public function testGenerateTokenProducesValidJwt(): void
    {
        $auth = $this->createAuthenticator();
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $jwt = $auth->generateToken($user);
        $decoded = (array) JWT::decode($jwt, new Key($this->secretKey, 'HS256'));

        $this->assertSame('user@example.com', $decoded['sub']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertGreaterThan(time(), $decoded['exp']);
    }

    public function testGenerateTokenIncludesCustomClaims(): void
    {
        $auth = $this->createAuthenticator();
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $jwt = $auth->generateToken($user, ['role' => 'admin', 'org' => 'acme']);
        $decoded = (array) JWT::decode($jwt, new Key($this->secretKey, 'HS256'));

        $this->assertSame('admin', $decoded['role']);
        $this->assertSame('acme', $decoded['org']);
    }

    public function testGenerateTokenRespectsCustomExpiration(): void
    {
        $auth = $this->createAuthenticator();
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $jwt = $auth->generateToken($user, [], 7200);
        $decoded = (array) JWT::decode($jwt, new Key($this->secretKey, 'HS256'));

        $this->assertEqualsWithDelta(time() + 7200, $decoded['exp'], 2);
    }

    public function testGeneratedTokenCanBeAuthenticatedBack(): void
    {
        $auth = $this->createAuthenticator();
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $jwt = $auth->generateToken($user);
        $result = $auth->authenticate($this->bearerRequest($jwt));

        $this->assertSame('user@example.com', $result->getUserIdentifier());
    }

    public function testGenerateTokenWithDifferentAlgorithm(): void
    {
        $auth = $this->createAuthenticator(['algorithm' => 'HS384']);
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);

        $jwt = $auth->generateToken($user);
        $decoded = (array) JWT::decode($jwt, new Key($this->secretKey, 'HS384'));

        $this->assertSame('user@example.com', $decoded['sub']);
    }
}
