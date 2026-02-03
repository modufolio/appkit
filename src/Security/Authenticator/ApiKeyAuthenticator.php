<?php

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Psr7\Http\Response;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = []
    ) {
        $this->options = array_merge([
            'header_name' => 'X-API-KEY',
            'query_parameter' => null, // Optional: allow API key in query string
            'api_keys' => [], // Map of api_key => user_identifier
        ], $options);

        if (empty($this->options['api_keys'])) {
            throw new \InvalidArgumentException('API keys must be configured.');
        }
    }

    public function supports(ServerRequestInterface $request): bool
    {
        // Check header first
        if ($request->hasHeader($this->options['header_name'])) {
            return true;
        }

        // Check query parameter if configured
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

        if (empty($apiKey)) {
            throw new AuthenticationException('API key is empty.');
        }

        // Validate API key
        if (!isset($this->options['api_keys'][$apiKey])) {
            throw new AuthenticationException('Invalid API key.');
        }

        $identifier = $this->options['api_keys'][$apiKey];

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (\Exception $e) {
            throw new AuthenticationException('User not found for API key.', 0, $e);
        }

        return $user;
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        // We don't have the actual API key here, so we'll use an empty string
        // In practice, the API key would be stored in the token during authentication
        return new ApiKeyToken($user, $firewallName, '', $user->getRoles());
    }

    /**
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json(['error' => $exception->getMessage()], 401)
            ->withHeader('WWW-Authenticate', sprintf('%s realm="Access to the API"', $this->options['header_name']));
    }

    /**
     * @throws AuthenticationException
     */
    private function extractApiKey(ServerRequestInterface $request): string
    {
        // Try header first
        if ($request->hasHeader($this->options['header_name'])) {
            $apiKey = $request->getHeaderLine($this->options['header_name']);
            if (!empty($apiKey)) {
                return trim($apiKey);
            }
        }

        // Try query parameter if configured
        if ($this->options['query_parameter']) {
            $queryParams = $request->getQueryParams();
            if (isset($queryParams[$this->options['query_parameter']])) {
                $apiKey = $queryParams[$this->options['query_parameter']];
                if (!empty($apiKey)) {
                    return trim($apiKey);
                }
            }
        }

        throw new AuthenticationException('Missing API key.');
    }
}
