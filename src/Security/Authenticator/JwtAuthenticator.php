<?php

namespace Modufolio\Appkit\Security\Authenticator;

use App\Logger\Log;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JwtAuthenticator extends AbstractAuthenticator
{
    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        private BruteForceProtectionInterface $bruteForceProtection,
        array $options = []
    ) {
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

        // Note: For JWT, we'll use IP-based rate limiting since we don't have the identifier yet
        // Check if IP is locked due to too many JWT failures
        if ($this->bruteForceProtection->isLocked('jwt:' . $clientIp, $clientIp)) {
            $remainingTime = $this->bruteForceProtection->getRemainingLockoutTime('jwt:' . $clientIp, $clientIp);

            Log::warning('JWT authentication blocked: IP temporarily locked due to too many failed attempts', [
                'ip' => $clientIp,
                'remaining_lockout_seconds' => $remainingTime,
            ]);

            throw new AuthenticationException(
                sprintf('Too many failed authentication attempts. Try again in %d seconds.', $remainingTime)
            );
        }

        try {
            $payload = $this->extractAndValidateToken($request);

            $userIdentifierClaim = $this->options['user_identifier_claim'];
            if (!isset($payload[$userIdentifierClaim])) {
                $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);

                Log::warning('JWT authentication failed: Missing user identifier claim', [
                    'claim' => $userIdentifierClaim,
                    'ip' => $clientIp,
                    'failure_count' => $this->bruteForceProtection->getFailureCount('jwt:' . $clientIp, $clientIp),
                ]);
                throw new AuthenticationException(sprintf('JWT payload missing "%s" claim.', $userIdentifierClaim));
            }

            $identifier = $payload[$userIdentifierClaim];

            try {
                $user = $this->userProvider->loadUserByIdentifier($identifier);

                // Successful authentication - reset failure counter
                $this->bruteForceProtection->recordSuccess('jwt:' . $clientIp, $clientIp);
                $this->bruteForceProtection->recordSuccess($identifier, $clientIp);

                Log::info('Successful JWT authentication', [
                    'username' => $identifier,
                    'ip' => $clientIp,
                ]);

                return $user;
            } catch (\Exception $e) {
                $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);
                $this->bruteForceProtection->recordFailure($identifier, $clientIp);

                Log::warning('JWT authentication failed: User not found', [
                    'username' => $identifier,
                    'ip' => $clientIp,
                    'failure_count' => $this->bruteForceProtection->getFailureCount($identifier, $clientIp),
                ]);
                throw new AuthenticationException('User not found for JWT token.', 0, $e);
            }
        } catch (AuthenticationException $e) {
            // Re-throw after ensuring logging
            throw $e;
        }
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        // Extract payload from request if available
        $payload = [];

        return new JwtToken($user, $firewallName, $payload, $user->getRoles());
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
            $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);

            Log::warning('JWT authentication failed: Missing or invalid Authorization header', [
                'ip' => $clientIp,
                'failure_count' => $this->bruteForceProtection->getFailureCount('jwt:' . $clientIp, $clientIp),
            ]);
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        $token = substr($authHeader, strlen($prefix));

        if (empty($token)) {
            $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);

            Log::warning('JWT authentication failed: Empty token', [
                'ip' => $clientIp,
                'failure_count' => $this->bruteForceProtection->getFailureCount('jwt:' . $clientIp, $clientIp),
            ]);
            throw new AuthenticationException('JWT token is empty.');
        }

        try {
            $decoded = JWT::decode($token, new Key($this->options['secret_key'], $this->options['algorithm']));
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // Don't record failure for expired tokens (valid tokens, just expired)
            Log::warning('JWT authentication failed: Token expired', [
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException('JWT token has expired.', 0, $e);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);

            Log::warning('JWT authentication failed: Invalid signature', [
                'ip' => $clientIp,
                'failure_count' => $this->bruteForceProtection->getFailureCount('jwt:' . $clientIp, $clientIp),
            ]);
            throw new AuthenticationException('JWT token signature is invalid.', 0, $e);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            // Don't record failure for not-yet-valid tokens (valid tokens, just early)
            Log::warning('JWT authentication failed: Token not yet valid', [
                'ip' => $clientIp,
            ]);
            throw new AuthenticationException('JWT token is not yet valid.', 0, $e);
        } catch (\Exception $e) {
            $this->bruteForceProtection->recordFailure('jwt:' . $clientIp, $clientIp);

            Log::error('JWT authentication error: ' . $e->getMessage(), [
                'ip' => $clientIp,
                'exception' => get_class($e),
                'failure_count' => $this->bruteForceProtection->getFailureCount('jwt:' . $clientIp, $clientIp),
            ]);
            throw new AuthenticationException('Invalid JWT token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a JWT token for a user
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
