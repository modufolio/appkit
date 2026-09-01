<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\DependencyInjection\ParameterBag;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Routing\RouterInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserChecker;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasher;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Factory\Psr17Factory;
use Modufolio\Psr7\Http\Response;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The container half of the kernel: service resolution with circular-chain
 * and did-you-mean diagnostics, repositories, and parameters.
 *
 * Behavior only: every property this trait touches is declared on {@see Kernel},
 * which composes it. Method names, visibility and signatures are unchanged from
 * their previous home on the kernel.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppContainer
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \Exception
     * @throws DbalException
     */
    public function get(string $id, ?string $interface = null): mixed
    {
        return $this->resolve($id, $interface, []);
    }

    /**
     * Apply the service definitions declared in config/services.php.
     *
     * Definitions take precedence over everything else in the container, so an
     * application can override a kernel core service by re-declaring its id.
     * Call before boot(), alongside configureSecurity().
     */
    public function configureServices(ServiceConfigurator $configurator): static
    {
        $this->services = $configurator->definitions + $this->services;
        $this->sharedServices = $configurator->shared + $this->sharedServices;
        $this->deprecatedServices = $configurator->deprecated + $this->deprecatedServices;

        return $this;
    }

    /**
     * Core services the kernel wires itself — every interface backed by a
     * kernel accessor or a dependency-free construction. Applications on
     * config/services.php only declare what they add on top; the legacy
     * config/interfaces.php path replaces this map entirely.
     *
     * @return array<class-string, \Closure>
     */
    protected function coreServices(): array
    {
        return [
            CsrfTokenManagerInterface::class => fn () => $this->csrfTokenManager(),
            DebugStack::class => fn () => $this->debugStack,
            EntityManagerInterface::class => fn () => $this->entityManager(),
            Environment::class => fn () => $this->environment(),
            FlashBagAwareSessionInterface::class => fn () => $this->session(),
            FlashBagInterface::class => fn () => $this->session()->getFlashBag(),
            ParameterResolverInterface::class => fn () => $this->parameterResolver(),
            ResponseFactoryInterface::class => fn () => new Psr17Factory(),
            ResponseInterface::class => fn () => new Response(),
            RouterInterface::class => fn () => $this->router(),
            SerializerInterface::class => fn () => $this->serializer(),
            ServerRequestInterface::class => fn () => $this->request(),
            SessionInterface::class => fn () => $this->session(),
            TokenStorageInterface::class => fn () => $this->tokenStorage(),
            UrlGeneratorInterface::class => fn () => $this->urlGenerator(),
            UserCheckerInterface::class => fn () => new UserChecker(),
            UserPasswordHasherInterface::class => fn () => new UserPasswordHasher(),
            UserProviderInterface::class => fn () => $this->userProvider(),
            ValidatorInterface::class => fn () => $this->validator(),
        ];
    }

    /**
     * @param array<string, true> $resolving
     *
     * @throws NotFoundException
     * @throws DbalException
     */
    protected function resolve(string $id, ?string $interface, array $resolving): mixed
    {
        // The property-based stack survives nested get() calls made from
        // inside factory closures, so a cycle through any number of services
        // is caught and reported as the full chain, not a stack overflow.
        if (isset($this->resolvingStack[$id])) {
            $chain = implode(' -> ', [...array_keys($this->resolvingStack), $id]);
            throw new \RuntimeException("Circular dependency detected: {$chain}");
        }

        $this->resolvingStack[$id] = true;

        try {
            if ($this->isKernelClass($id)) {
                throw new \LogicException(sprintf('Injecting "%s" (the kernel/app) as a dependency is not allowed. Use specific service accessors instead (e.g. router(), serializer(), session()).', $id));
            }

            if (isset($this->services[$id])) {
                $this->triggerServiceDeprecation($id);

                if (isset($this->sharedServices[$id], $this->instances[$id])) {
                    $instance = $this->instances[$id];
                } else {
                    $instance = $this->services[$id]($this);

                    if (isset($this->sharedServices[$id])) {
                        $this->instances[$id] = $instance;
                    }
                }
            } elseif (array_key_exists($id, $this->interfaceMap)) {
                $instance = $this->interfaceMap[$id]();
            } elseif (isset($this->instances[$id])) {
                $instance = $this->instances[$id];
            } elseif (array_key_exists($id, $this->repositories())) {
                $instance = $this->getRepository($id);
            } elseif (isset($this->authenticators[$id])) {
                $instance = $this->authenticators[$id]($this);
            } elseif (isset($this->factories[$id])) {
                $instance = $this->factories[$id]($this);
            } elseif ($this->fallbackContainer?->has($id)) {
                $instance = $this->fallbackContainer->get($id);
            } else {
                throw new NotFoundException($this->notFoundMessage($id));
            }

            if ($interface && !$instance instanceof $interface) {
                throw new \RuntimeException(sprintf('Service "%s" does not implement required interface "%s".', get_debug_type($instance), $interface));
            }

            return $instance;
        } catch (\Error $e) {
            if ($e instanceof \ArgumentCountError) {
                throw new \InvalidArgumentException(\sprintf('Class "%s" has required constructor arguments that dont exist in container.', $id), 0, $e);
            }
            throw $e;
        } finally {
            unset($this->resolvingStack[$id]);
        }
    }

    /**
     * Craft the not-found message: name the requesting service when the miss
     * happened inside another factory, and suggest near-miss ids — including
     * which module registered them, when a module did.
     * @throws DbalException
     */
    private function notFoundMessage(string $id): string
    {
        $message = "Class or parameter {$id} is not found.";

        // The id itself is on top of the stack; the entry below it (if any)
        // is the service whose factory asked for it.
        $stack = array_keys($this->resolvingStack);
        if (count($stack) >= 2) {
            $message .= sprintf(' (needed by "%s")', $stack[count($stack) - 2]);
        }

        $known = [
            ...array_keys($this->services),
            ...array_keys($this->interfaceMap),
            ...array_keys($this->instances),
            ...array_keys($this->repositories()),
            ...array_keys($this->authenticators),
            ...array_keys($this->factories),
        ];

        $alternatives = [];
        foreach (array_unique($known) as $candidate) {
            $shortId = substr((string) strrchr('\\'.$id, '\\'), 1);
            $shortCandidate = substr((string) strrchr('\\'.$candidate, '\\'), 1);
            if (levenshtein($shortId, $shortCandidate) <= strlen($shortId) / 3 || str_contains($candidate, $id)) {
                $alternatives[] = isset($this->serviceProvenance[$candidate])
                    ? sprintf('"%s" (from module "%s")', $candidate, $this->serviceProvenance[$candidate])
                    : sprintf('"%s"', $candidate);
            }
        }

        if ([] !== $alternatives) {
            $message .= ' Did you mean '.implode(', ', array_slice($alternatives, 0, 3)).'?';
        }

        return $message;
    }

    /**
     * Warn once per process when a deprecated service id is resolved.
     */
    private function triggerServiceDeprecation(string $id): void
    {
        if (!isset($this->deprecatedServices[$id]) || isset($this->triggeredDeprecations[$id])) {
            return;
        }

        $this->triggeredDeprecations[$id] = true;
        trigger_error($this->deprecatedServices[$id], \E_USER_DEPRECATED);
    }

    /**
     * Returns true if $id refers to the kernel itself, any of its parent classes,
     * or any interface it implements (e.g. AppInterface, ContainerInterface).
     */
    private function isKernelClass(string $id): bool
    {
        return (class_exists($id) || interface_exists($id)) && is_a(static::class, $id, true);
    }

    /**
     * @throws DbalException
     */
    public function has(string $id): bool
    {
        if ($this->isKernelClass($id)) {
            return false;
        }

        return isset($this->services[$id])
            || isset($this->instances[$id])
            || array_key_exists($id, $this->interfaceMap)
            || array_key_exists($id, $this->repositories())
            || isset($this->factories[$id])
            || ($this->fallbackContainer?->has($id) ?? false);
    }

    public function setFallbackContainer(?ContainerInterface $container): static
    {
        if ($container === $this) {
            throw new \LogicException('The kernel cannot be its own fallback container.');
        }

        $this->fallbackContainer = $container;

        return $this;
    }

    /**
     * @return array<class-string, class-string>
     *
     * @throws DbalException
     */
    public function repositories(): array
    {
        return $this->repositories ??= $this->getRepositoriesAndEntities();
    }

    /**
     * @return array<class-string, class-string>
     *
     * @throws DbalException
     */
    protected function getRepositoriesAndEntities(): array
    {
        $repositories = [];
        $metadata = $this->entityManager()->getMetadataFactory()->getAllMetadata();

        foreach ($metadata as $classMetadata) {
            $entityClass = $classMetadata->getName();
            $repositoryClass = $this->entityManager()->getRepository($entityClass)::class;
            $repositories[$repositoryClass] = $entityClass;
        }

        return $repositories;
    }

    /**
     * @throws DbalException
     */
    public function getRepository(string $repositoryClass): object
    {
        $repositories = $this->repositories();

        if (!array_key_exists($repositoryClass, $repositories)) {
            throw new \InvalidArgumentException("Repository {$repositoryClass} not found.");
        }

        $entityClass = $repositories[$repositoryClass];

        return $this->entityManager()->getRepository($entityClass);
    }

    public function getParameterBag(): ParameterBag
    {
        return $this->parameterBag;
    }

    /**
     * @return array<mixed>|bool|string|int|float|null
     */
    public function getParameter(string $name): array|bool|string|int|float|null
    {
        return $this->parameterBag->get($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->parameterBag->has($name);
    }

    /**
     * @param array<mixed>|bool|string|int|float|null $value
     */
    public function setParameter(string $name, array|bool|string|int|float|null $value): void
    {
        $this->parameterBag->set($name, $value);
    }
}
