<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private array $options;
    private ?string $lastApiKey = null;

    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = [],
    ) {
        $this->options = array_merge([
            'header_name' => 'X-API-KEY',
            'query_parameter' => null,
            'api_keys' => [],
        ], $options);

        if (empty($this->options['api_keys'])) {
            throw new \InvalidArgumentException('API keys must be configured.');
        }
    }

    public function supports(ServerRequestInterface $request): bool
    {
        if ($request->hasHeader($this->options['header_name'])) {
            return true;
        }

        if ($this->options['query_parameter']) {
            $queryParams = $request->getQueryParams();

            return isset($queryParams[$this->options['query_parameter']]);
        }

        return false;
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        $apiKey = $this->extractApiKey($request);

        if ('' === $apiKey) {
            throw new AuthenticationException('API key is empty.');
        }

        $identifier = $this->resolveIdentifier($apiKey);
        if (null === $identifier) {
            throw new AuthenticationException('Invalid API key.');
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException $e) {
            throw new AuthenticationException('User not found for API key.', 0, $e);
        }

        $this->lastApiKey = $apiKey;

        return $user;
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new ApiKeyToken($user, $firewallName, $this->lastApiKey, $user->getRoles());
    }

    /**
     * Generic 401. The specific reason (missing key / invalid key / user not
     * found for key) stays in the log — distinguishing them in the response
     * would help an attacker map valid keys.
     *
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json([
            'error' => 'invalid_api_key',
            'error_description' => 'Authentication required.',
        ], 401)
            ->withHeader('WWW-Authenticate', sprintf('ApiKey realm="Access to the API", header="%s"', $this->options['header_name']));
    }

    /**
     * Constant-time lookup of the configured api_keys map.
     *
     * Why: `isset($map[$key])` short-circuits and leaks via timing whether a
     * supplied key partially matches a stored key.
     */
    private function resolveIdentifier(string $apiKey): ?string
    {
        $matchedIdentifier = null;
        foreach ($this->options['api_keys'] as $configuredKey => $identifier) {
            if (hash_equals((string) $configuredKey, $apiKey)) {
                $matchedIdentifier = (string) $identifier;
            }
        }

        return $matchedIdentifier;
    }

    /**
     * @throws AuthenticationException
     */
    private function extractApiKey(ServerRequestInterface $request): string
    {
        if ($request->hasHeader($this->options['header_name'])) {
            $apiKey = trim($request->getHeaderLine($this->options['header_name']));
            if ('' !== $apiKey) {
                return $apiKey;
            }
        }

        if ($this->options['query_parameter']) {
            $queryParams = $request->getQueryParams();
            $value = $queryParams[$this->options['query_parameter']] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        throw new AuthenticationException('Missing API key.');
    }
}
