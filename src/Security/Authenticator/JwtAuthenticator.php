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

    private array $options;
    private LoggerInterface $logger;
    private array $lastPayload = [];

    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->options = array_merge([
            'secret_key' => null,
            'algorithm' => 'HS256',
            'header_name' => 'Authorization',
            'token_prefix' => 'Bearer',
            'user_identifier_claim' => 'sub',
        ], $options);

        if (empty($this->options['secret_key'])) {
            throw new \InvalidArgumentException('JWT secret_key must be configured.');
        }

        if (!in_array($this->options['algorithm'], self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported JWT algorithm "%s". Supported: %s.',
                $this->options['algorithm'],
                implode(', ', self::SUPPORTED_ALGORITHMS),
            ));
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
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json(['error' => $exception->getMessage()], 401)
            ->withHeader('WWW-Authenticate', $this->options['token_prefix'] . ' realm="Access to the API"');
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
            $decoded = JWT::decode($token, new Key($this->options['secret_key'], $this->options['algorithm']));
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
     * Generate a JWT token for a user.
     *
     * Note: this is a convenience helper for issuance. Callers wiring this in
     * production should consider extracting it into a dedicated issuer service.
     */
    public function generateToken(UserInterface $user, array $customClaims = [], ?int $expiresIn = 3600): string
    {
        $now = time();

        $payload = array_merge([
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'sub' => $user->getUserIdentifier(),
        ], $customClaims);

        return JWT::encode($payload, $this->options['secret_key'], $this->options['algorithm']);
    }
}
