<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\OAuth\OAuthServiceInterface;
use Modufolio\Appkit\Security\Token\OAuthToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OAuth 2.1 Authenticator
 *
 * Authenticates requests using OAuth 2.1 Bearer tokens validated against the
 * configured OAuth service.
 */
class OAuthAuthenticator extends AbstractAuthenticator
{
    private array $options;
    private LoggerInterface $logger;
    private array $lastScopes = [];

    public function __construct(
        private OAuthServiceInterface $oauthService,
        array $options = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->options = array_merge([
            'header_name' => 'Authorization',
            'token_prefix' => 'Bearer',
        ], $options);
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

        try {
            $accessToken = $this->extractToken($request);

            $tokenEntity = $this->oauthService->validateAccessToken($accessToken);

            if ($tokenEntity === null) {
                $this->logger->warning('OAuth authentication failed: Invalid or expired token', [
                    'ip' => $clientIp,
                ]);
                throw new AuthenticationException('Invalid or expired access token.');
            }

            $user = $tokenEntity->getUser();
            $scopes = $tokenEntity->getScopes();

            $this->logger->info('Successful OAuth authentication', [
                'username' => $user->getUserIdentifier(),
                'ip' => $clientIp,
                'client_id' => $tokenEntity->getClientId(),
                'scopes' => $scopes,
            ]);

            $this->lastScopes = $scopes;

            return $user;
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('OAuth authentication error: ' . $e->getMessage(), [
                'ip' => $clientIp,
                'exception' => get_class($e),
            ]);
            // Detail of the underlying error stays in the log; the client
            // gets a generic message.
            throw new AuthenticationException('Authentication failed.', 0, $e);
        }
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new OAuthToken($user, $firewallName, $this->lastScopes, $user->getRoles());
    }

    /**
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        $errorCode = str_contains(strtolower($exception->getMessage()), 'expired') ? 'invalid_token' : 'invalid_token';
        $challenge = sprintf(
            '%s realm="Access to the API", error="%s", error_description="%s"',
            $this->options['token_prefix'],
            $errorCode,
            $this->sanitizeForHeader($exception->getMessage()),
        );

        return Response::json(['error' => $exception->getMessage()], 401)
            ->withHeader('WWW-Authenticate', $challenge);
    }

    /**
     * @throws AuthenticationException
     */
    private function extractToken(ServerRequestInterface $request): string
    {
        $authHeader = $request->getHeaderLine($this->options['header_name']);
        $prefix = $this->options['token_prefix'] . ' ';

        if (!str_starts_with($authHeader, $prefix)) {
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        $token = trim(substr($authHeader, strlen($prefix)));

        if ($token === '') {
            throw new AuthenticationException('Access token is empty.');
        }

        return $token;
    }

    private function sanitizeForHeader(string $value): string
    {
        // RFC 7235 quoted-string disallows control chars, backslashes and quotes.
        return preg_replace('/[^\x20-\x7E]|["\\\\]/', '', $value) ?? '';
    }
}
