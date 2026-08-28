<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Google's OpenID Connect client: the authorization redirect, the code
 * exchange, and — the part that actually establishes trust — verifying the
 * ID token Google returns.
 *
 * The ID token is a JWT signed by Google. Verifying its signature against
 * Google's published keys is what lets us trust its claims without a second
 * round-trip: a token we can validate cryptographically cannot be forged, so
 * the `email`/`email_verified` inside it are authoritative.
 *
 * Depends only on PSR-18 (HTTP client) and PSR-17 (message factories), so the
 * framework carries no opinion about which HTTP library the app supplies.
 */
final class GoogleOAuthClient implements GoogleOAuthClientInterface
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const CERTS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';

    /** The issuers Google stamps into its ID tokens; both forms are valid. */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * Parsed JWKS, memoised for the life of the request.
     *
     * @var array<string, \Firebase\JWT\Key>|null
     */
    private ?array $keys = null;

    /**
     * @param non-empty-string $clientId
     * @param non-empty-string $clientSecret
     * @param non-empty-string $redirectUri absolute URL Google calls back
     * @param int              $leeway      clock-skew tolerance, in seconds
     */
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly int $leeway = 30,
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            // openid+email is the minimum that yields a verifiable identity;
            // profile adds the display name, which is nice-to-have only.
            'scope' => 'openid email profile',
            'state' => $state,
            // No refresh token: this is an interactive login, not offline
            // access to Google APIs on the user's behalf.
            'access_type' => 'online',
            // Always show the account chooser, so a shared browser cannot
            // silently reuse whoever logged in last.
            'prompt' => 'select_account',
        ]);

        return self::AUTH_ENDPOINT.'?'.$query;
    }

    public function authenticate(string $code): GoogleIdentity
    {
        $idToken = $this->exchangeCodeForIdToken($code);

        return $this->verifyIdToken($idToken);
    }

    /**
     * Trade the one-time authorization code for the ID token.
     *
     * @throws GoogleOAuthException
     */
    private function exchangeCodeForIdToken(string $code): string
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $request = $this->requestFactory->createRequest('POST', self::TOKEN_ENDPOINT)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw new GoogleOAuthException('Token exchange with Google failed.', 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded) || !isset($decoded['id_token']) || !is_string($decoded['id_token'])) {
            throw new GoogleOAuthException('Google token response carried no id_token.');
        }

        return $decoded['id_token'];
    }

    /**
     * Verify the ID token's signature and claims, and distil the identity.
     *
     * @throws GoogleOAuthException
     */
    private function verifyIdToken(string $idToken): GoogleIdentity
    {
        $original = JWT::$leeway;
        JWT::$leeway = $this->leeway;

        try {
            // decode() verifies the RS256 signature against Google's keys and
            // enforces exp/nbf/iat. It does NOT check iss/aud — those are our
            // responsibility, below.
            $claims = (array) JWT::decode($idToken, $this->jwks());
        } catch (\Throwable $e) {
            throw new GoogleOAuthException('Google ID token failed verification.', 0, $e);
        } finally {
            JWT::$leeway = $original;
        }

        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new GoogleOAuthException('Google ID token has an unexpected issuer.');
        }

        // The token must have been minted for THIS client, or it could be one
        // Google issued to a different app entirely.
        if (($claims['aud'] ?? null) !== $this->clientId) {
            throw new GoogleOAuthException('Google ID token was issued for another client.');
        }

        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new GoogleOAuthException('Google ID token carried no email.');
        }

        return new GoogleIdentity(
            subject: (string) ($claims['sub'] ?? ''),
            email: $email,
            // Google sends this as a real bool or the string "true".
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            name: isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
            hostedDomain: isset($claims['hd']) && is_string($claims['hd']) ? $claims['hd'] : null,
        );
    }

    /**
     * Google's current signing keys, as firebase/php-jwt Key objects.
     *
     * @return array<string, \Firebase\JWT\Key>
     *
     * @throws GoogleOAuthException
     */
    private function jwks(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $request = $this->requestFactory->createRequest('GET', self::CERTS_ENDPOINT);

        try {
            $response = $this->httpClient->sendRequest($request);
            $jwks = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            throw new GoogleOAuthException('Could not fetch Google signing keys.', 0, $e);
        }

        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new GoogleOAuthException('Google signing keys were malformed.');
        }

        return $this->keys = JWK::parseKeySet($jwks);
    }
}
