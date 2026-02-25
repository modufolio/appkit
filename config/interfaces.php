<?php

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Factory\Psr17Factory;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Routing\RouterInterface;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Security\User\UserChecker;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasher;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface as SymfonyUserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

return [
    SymfonyUserPasswordHasherInterface::class => fn () => new UserPasswordHasher(),
    TotpService::class => fn () => $this->totpService(),
    BruteForceProtectionInterface::class => fn () => $this->bruteForceProtection(),
    CsrfTokenManagerInterface::class => fn () => $this->csrfTokenManager(),
    DebugStack::class => fn () => $this->debugStack,
    EntityManagerInterface::class => fn () => $this->entityManager(),
    Environment::class => fn () => $this->environment(),
    FlashBagAwareSessionInterface::class => fn () => $this->session(),
    FlashBagInterface::class => fn () => $this->session()->getFlashBag(),
    ParameterResolverInterface::class => fn () => $this->parameterResolver(),
    ResponseFactoryInterface::class => fn () => new Psr17Factory(),
    RouterInterface::class => fn () => $this->router(),
    SerializerInterface::class => fn () => $this->serializer(),
    ServerRequestInterface::class => fn () => $this->request(),
    SessionInterface::class => fn () => $this->session(),
    TokenStorageInterface::class => fn () => $this->tokenStorage(),
    UrlGeneratorInterface::class => fn () => $this->router()->getUrlGenerator(),
    UserCheckerInterface::class => fn () => new UserChecker(),
    UserPasswordHasherInterface::class => fn () => new UserPasswordHasher(),
    UserProviderInterface::class => fn () => $this->userProvider(),
    ValidatorInterface::class => fn () => $this->validator(),
];