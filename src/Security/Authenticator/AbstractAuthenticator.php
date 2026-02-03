<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractAuthenticator implements AuthenticatorInterface
{
    abstract public function supports(ServerRequestInterface $request): bool;
    abstract public function authenticate(ServerRequestInterface $request): UserInterface;
    abstract public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface;
    abstract public function createToken(UserInterface $user, string $firewallName): TokenInterface;
}
