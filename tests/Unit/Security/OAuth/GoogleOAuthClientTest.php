<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\OAuth;

use Firebase\JWT\JWT;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthClient;
use Modufolio\Appkit\Security\OAuth\Google\GoogleOAuthException;
use Modufolio\Psr7\Http\Factory\Psr17Factory;
use Modufolio\Psr7\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The parts of the Google client that do not need Google: PKCE on both legs
 * and the signing-key cache surviving a key rotation.
 */
#[CoversClass(GoogleOAuthClient::class)]
final class GoogleOAuthClientTest extends TestCase
{
    private const CLIENT_ID = 'client-id.apps.googleusercontent.com';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    private function httpClient(): ClientInterface
    {
        $exchange = function (RequestInterface $request): ResponseInterface {
            $this->sent[] = $request;

            return array_shift($this->queue) ?? throw new \LogicException('No response queued for '.$request->getUri());
        };

        return new class($exchange) implements ClientInterface {
            public function __construct(private \Closure $exchange)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->exchange)($request);
            }
        };
    }

    private function client(): GoogleOAuthClient
    {
        $factory = new Psr17Factory();

        return new GoogleOAuthClient(self::CLIENT_ID, 'secret', 'https://app.example/cb', $this->httpClient(), $factory, $factory);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: array<string, string>} private key and its JWK
     */
    private function keyPair(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        $b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [$key, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]];
    }

    private function idToken(\OpenSSLAsymmetricKey $key, string $kid): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '123',
            'email' => 'member@example.com',
            'email_verified' => true,
            'iat' => $now,
            'exp' => $now + 300,
        ], $key, 'RS256', $kid);
    }

    public function testAuthorizationUrlCarriesTheS256ChallengeOfTheVerifier(): void
    {
        $verifier = GoogleOAuthClient::generateCodeVerifier();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $verifier);

        parse_str((string) parse_url($this->client()->authorizationUrl('st', $verifier), PHP_URL_QUERY), $query);

        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertSame('st', $query['state']);
    }

    public function testAuthorizationUrlWithoutAVerifierSendsNoChallenge(): void
    {
        parse_str((string) parse_url($this->client()->authorizationUrl('st'), PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('code_challenge', $query);
        $this->assertArrayNotHasKey('code_challenge_method', $query);
    }

    public function testTheExchangeSendsTheVerifierAndVerifiesTheIdToken(): void
    {
        [$key, $jwk] = $this->keyPair('k1');
        $this->queue[] = $this->json(['id_token' => $this->idToken($key, 'k1')]);
        $this->queue[] = $this->json(['keys' => [$jwk]]);

        $identity = $this->client()->authenticate('the-code', 'the-verifier');

        $this->assertSame('member@example.com', $identity->email);
        parse_str((string) $this->sent[0]->getBody(), $body);
        $this->assertSame('the-verifier', $body['code_verifier']);
        $this->assertSame('the-code', $body['code']);
    }

    /**
     * Google rotates its keys; a worker that cached the old set must fetch
     * again when a token arrives signed by a key it does not know.
     */
    public function testAnUnknownKidRefetchesTheSigningKeysOnce(): void
    {
        [$oldKey, $oldJwk] = $this->keyPair('old');
        [$newKey, $newJwk] = $this->keyPair('new');
        $client = $this->client();

        // First login: keys fetched and cached.
        $this->queue[] = $this->json(['id_token' => $this->idToken($oldKey, 'old')]);
        $this->queue[] = $this->json(['keys' => [$oldJwk]]);
        $client->authenticate('code-1');

        // Google rotated: the token is signed with a key the cache lacks.
        $this->queue[] = $this->json(['id_token' => $this->idToken($newKey, 'new')]);
        $this->queue[] = $this->json(['keys' => [$newJwk]]);
        $identity = $client->authenticate('code-2');

        $this->assertSame('member@example.com', $identity->email);
        $this->assertCount(4, $this->sent, 'token, certs, token, certs again');
        $this->assertSame([], $this->queue);
    }

    public function testAForgedTokenStillFailsAfterTheRefetch(): void
    {
        [$goodKey, $goodJwk] = $this->keyPair('good');
        [$rogueKey] = $this->keyPair('rogue');
        $client = $this->client();

        $this->queue[] = $this->json(['id_token' => $this->idToken($goodKey, 'good')]);
        $this->queue[] = $this->json(['keys' => [$goodJwk]]);
        $client->authenticate('code-1');

        $this->queue[] = $this->json(['id_token' => $this->idToken($rogueKey, 'rogue')]);
        $this->queue[] = $this->json(['keys' => [$goodJwk]]);

        $this->expectException(GoogleOAuthException::class);
        $client->authenticate('code-2');
    }
}
