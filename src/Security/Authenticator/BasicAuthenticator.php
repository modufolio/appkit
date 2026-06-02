<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class BasicAuthenticator extends AbstractAuthenticator
{
    /**
     * Pre-computed bcrypt hash of a random string. Used as a dummy target for
     * password verification when the user does not exist, so that the response
     * timing of "no such user" matches "wrong password".
     */
    private const DUMMY_HASH = '$2y$12$abcdefghijklmnopqrstuuGfQ7w0rqXjK0LhV0XjY6wWyJ4Z7lYqe';

    public function __construct(
        private UserProviderInterface $userProvider,
        private ?UserPasswordHasherInterface $passwordHasher = null,
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

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException) {
            // Equalize timing so attackers cannot distinguish unknown users
            // from wrong passwords.
            $this->verifyDummyPassword($password);
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new AuthenticationException('User does not support password authentication.');
        }

        $valid = null !== $this->passwordHasher
            ? $this->passwordHasher->isPasswordValid($user, $password)
            : password_verify($password, (string) $user->getPassword());

        if (!$valid) {
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json(['error' => 'Invalid credentials.'], 401)
            ->withHeader('WWW-Authenticate', 'Basic realm="Access to the API"');
    }

    private function verifyDummyPassword(string $password): void
    {
        if (null !== $this->passwordHasher) {
            $this->passwordHasher->verifyDummy($password);

            return;
        }
        password_verify($password, self::DUMMY_HASH);
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
        if (false === $decoded || !str_contains($decoded, ':')) {
            throw new AuthenticationException('Invalid Basic authentication header.');
        }

        [$username, $password] = explode(':', $decoded, 2);

        if ('' === $username || '' === $password) {
            throw new AuthenticationException('Username and password cannot be empty.');
        }

        return [$username, $password];
    }
}
