<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface AuthenticatorInterface
{
    public function supports(ServerRequestInterface $request): bool;
    public function authenticate(ServerRequestInterface $request): UserInterface;
    public function createToken(UserInterface $user, string $firewallName): TokenInterface;
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface;

}
