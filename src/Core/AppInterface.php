<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Application interface defining the contract between framework and application.
 *
 * This interface represents what the framework core expects from a concrete
 * application implementation. It extends PSR interfaces for interoperability
 * and defines the essential services that framework components depend on.
 *
 * Implementations should extend the abstract Kernel class which provides
 * the core infrastructure (routing, middleware, DI container).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AppInterface extends ContainerInterface, RequestHandlerInterface, ResetInterface
{
    // ============================================================================
    // ENVIRONMENT & CONFIGURATION
    // ============================================================================

    public function environment(): Environment;

    public function getParameter(string $name): array|bool|string|int|float|null;

    public function hasParameter(string $name): bool;

    // ============================================================================
    // CORE SERVICES
    // ============================================================================

    public function serializer(): SerializerInterface;

    public function parameterResolver(): ParameterResolverInterface;

    public function validator(): ValidatorInterface;

    public function entityManager(): EntityManagerInterface;

    // ============================================================================
    // REQUEST & SESSION
    // ============================================================================

    public function request(): ServerRequestInterface;

    public function session(): FlashBagAwareSessionInterface;

    // ============================================================================
    // SECURITY
    // ============================================================================

    public function tokenStorage(): TokenStorageInterface;

    public function userProvider(): UserProviderInterface;

    // ============================================================================
    // ROUTING
    // ============================================================================

    public function generateUrl(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH
    ): string;
}
