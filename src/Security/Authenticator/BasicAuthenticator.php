<?php

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Psr7\Http\Response;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class BasicAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserProviderInterface $userProvider
    ) {
    }

    public function supports(ServerRequestInterface $request): bool
    {
        $authHeader = $request->getHeaderLine('Authorization');
        return str_starts_with($authHeader, 'Basic ');
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        [$identifier, $password] = $this->extractCredentials($request);
        $user = $this->userProvider->loadUserByIdentifier($identifier);

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new AuthenticationException('User does not support password authentication.');
        }

        if (password_verify($password, $user->getPassword()) === false) {
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json(['error' => $exception->getMessage()], 401)
            ->withHeader('WWW-Authenticate', 'Basic realm="Access to the API"');
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new UsernamePasswordToken($user, $firewallName, $user->getRoles());
    }

    /**
     * @throws AuthenticationException
     */
    private function extractCredentials(ServerRequestInterface $request): array
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Basic ')) {
            throw new AuthenticationException('Missing or invalid Authorization header.');
        }

        $base64Credentials = substr($authHeader, 6);
        $decoded = base64_decode($base64Credentials, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new AuthenticationException('Invalid Basic authentication header.');
        }

        [$username, $password] = explode(':', $decoded, 2);

        if (empty($username) || empty($password)) {
            throw new AuthenticationException('Username and password cannot be empty.');
        }

        return [$username, $password];
    }
}
