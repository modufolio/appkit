<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class JwtAuthenticator extends AbstractAuthenticator
{
    private const SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];
    private const HMAC_ALGORITHMS = ['HS256', 'HS384', 'HS512'];

    private array $options;
    private LoggerInterface $logger;
    private array $lastPayload = [];

    /**
     * Options:
     *   - algorithm           one of SUPPORTED_ALGORITHMS (required)
     *   - signing_key         used only by generateToken(); for asymmetric algos this is the private key
     *   - verification_key    used only by decode(); for asymmetric algos this is the public key
     *   - secret_key          DEPRECATED back-compat: when set, used as both signing and verification key (HMAC only)
     *   - issuer              when set, the 'iss' claim must equal this value
     *   - audience            when set, the 'aud' claim must contain this value (string or list)
     *   - header_name         Authorization header name (default 'Authorization')
     *   - token_prefix        scheme prefix (default 'Bearer')
     *   - user_identifier_claim   claim used to look up the user (default 'sub')
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->options = array_merge([
            'algorithm' => 'HS256',
            'signing_key' => null,
            'verification_key' => null,
            'secret_key' => null,
            'issuer' => null,
            'audience' => null,
            'header_name' => 'Authorization',
            'token_prefix' => 'Bearer',
            'user_identifier_claim' => 'sub',
        ], $options);

        if (!in_array($this->options['algorithm'], self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported JWT algorithm "%s". Supported: %s.',
                $this->options['algorithm'],
                implode(', ', self::SUPPORTED_ALGORITHMS),
            ));
        }

        $isHmac = in_array($this->options['algorithm'], self::HMAC_ALGORITHMS, true);

        // Back-compat: a single secret_key fills both slots for HMAC. Reject it for
        // asymmetric algorithms — sharing one key as both private and public is wrong.
        if (!empty($this->options['secret_key'])) {
            if (!$isHmac) {
                throw new \InvalidArgumentException(
                    'secret_key is only valid with HMAC algorithms (HS256/HS384/HS512). '
                    . 'Use signing_key (private) and verification_key (public) for asymmetric algorithms.',
                );
            }
            $this->options['signing_key'] ??= $this->options['secret_key'];
            $this->options['verification_key'] ??= $this->options['secret_key'];
        }

        if (empty($this->options['verification_key'])) {
            throw new \InvalidArgumentException(
                'JWT verification_key must be configured (or secret_key for HMAC algorithms).',
            );
        }
    }

    public function supports(ServerRequestInterface $request): bool
    {
        $authHeader = $request->getHeaderLine($this->options['header_name']);
        return str_starts_with($authHeader, $this->options['token_prefix'] . ' ');
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $payload = $this->extractAndValidateToken($request);
        $this->validateClaims($payload, $clientIp);

        $userIdentifierClaim = $this->options['user_identifier_claim'];
        if (!isset($payload[$userIdentifierClaim])) {
            $this->logger->warning('JWT authentication failed: Missing user identifier claim', [
                'claim' => $userIdentifierClaim,
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException(sprintf('JWT payload missing "%s" claim.', $userIdentifierClaim));
        }

        $identifier = (string) $payload[$userIdentifierClaim];

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException $e) {
            $this->logger->warning('JWT authentication failed: User not found', [
                'username' => $identifier,
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException('User not found for JWT token.', 0, $e);
        }

        $this->logger->info('Successful JWT authentication', [
            'username' => $identifier,
            'ip' => $clientIp,
        ]);

        $this->lastPayload = $payload;

        return $user;
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new JwtToken($user, $firewallName, $this->lastPayload, $user->getRoles());
    }

    /**
     * Generic 401. The exact reason (expired / invalid signature / bad audience)
     * is logged but never returned to the client — it would be free reconnaissance.
     *
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json([
            'error' => 'invalid_token',
            'error_description' => 'Authentication required.',
        ], 401)
            ->withHeader('WWW-Authenticate', $this->options['token_prefix'] . ' realm="Access to the API", error="invalid_token"');
    }

    /**
     * @throws AuthenticationException
     */
    private function extractAndValidateToken(ServerRequestInterface $request): array
    {
        $authHeader = $request->getHeaderLine($this->options['header_name']);
        $prefix = $this->options['token_prefix'] . ' ';
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        if (!str_starts_with($authHeader, $prefix)) {
            $this->logger->warning('JWT authentication failed: Missing or invalid Authorization header', [
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        $token = trim(substr($authHeader, strlen($prefix)));

        if ($token === '') {
            $this->logger->warning('JWT authentication failed: Empty token', [
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException('JWT token is empty.');
        }

        try {
            $decoded = JWT::decode($token, new Key($this->options['verification_key'], $this->options['algorithm']));
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            $this->logger->warning('JWT authentication failed: Token expired', ['ip' => $clientIp]);
            throw new AuthenticationException('JWT token has expired.', 0, $e);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            $this->logger->warning('JWT authentication failed: Invalid signature', ['ip' => $clientIp]);
            throw new AuthenticationException('JWT token signature is invalid.', 0, $e);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            $this->logger->warning('JWT authentication failed: Token not yet valid', ['ip' => $clientIp]);
            throw new AuthenticationException('JWT token is not yet valid.', 0, $e);
        } catch (\Exception $e) {
            $this->logger->error('JWT authentication error: ' . $e->getMessage(), [
                'ip' => $clientIp,
                'exception' => get_class($e),
            ]);
            throw new AuthenticationException('Invalid JWT token.', 0, $e);
        }
    }

    /**
     * Enforce iss / aud claims when configured. Tokens from other services
     * or other audiences are rejected even if their signature is valid.
     *
     * @throws AuthenticationException
     */
    private function validateClaims(array $payload, string $clientIp): void
    {
        if ($this->options['issuer'] !== null) {
            $iss = $payload['iss'] ?? null;
            if ($iss !== $this->options['issuer']) {
                $this->logger->warning('JWT authentication failed: Invalid issuer', [
                    'expected' => $this->options['issuer'],
                    'received' => is_scalar($iss) ? (string) $iss : '(non-scalar)',
                    'ip' => $clientIp,
                ]);
                throw new AuthenticationException('JWT issuer mismatch.');
            }
        }

        if ($this->options['audience'] !== null) {
            $aud = $payload['aud'] ?? null;
            $audClaims = is_array($aud) ? $aud : [$aud];
            if (!in_array($this->options['audience'], $audClaims, true)) {
                $this->logger->warning('JWT authentication failed: Invalid audience', [
                    'expected' => $this->options['audience'],
                    'ip' => $clientIp,
                ]);
                throw new AuthenticationException('JWT audience mismatch.');
            }
        }
    }

    /**
     * Generate a JWT token for a user. Uses signing_key (the private key for
     * asymmetric algos). For production issuance consider extracting this into
     * a dedicated JwtIssuer service.
     */
    public function generateToken(UserInterface $user, array $customClaims = [], ?int $expiresIn = 3600): string
    {
        if (empty($this->options['signing_key'])) {
            throw new \LogicException(
                'Cannot generate JWT: signing_key is not configured. '
                . 'This authenticator was set up for verification only.',
            );
        }

        $now = time();
        $payload = array_merge([
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'sub' => $user->getUserIdentifier(),
        ], $this->options['issuer'] !== null ? ['iss' => $this->options['issuer']] : [],
           $this->options['audience'] !== null ? ['aud' => $this->options['audience']] : [],
           $customClaims);

        return JWT::encode($payload, $this->options['signing_key'], $this->options['algorithm']);
    }
}
