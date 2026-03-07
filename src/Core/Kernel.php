<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\DependencyInjection\ParameterBag;
use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use Modufolio\Appkit\Doctrine\EntityManagerFactory;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Exception\ExceptionHandlerInterface;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Routing\Router;
use Modufolio\Appkit\Routing\RouterInterface;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Psr7\Http\Emitter;
use Modufolio\Psr7\Http\EmitterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abstract Kernel - Core framework container and request handler.
 *
 * RoadRunner Compatibility:
 * This class is designed to work with RoadRunner's worker model where the same
 * instance handles multiple requests. State management follows these principles:
 *
 * - Application-level state: Configuration, services, factories (persist across requests)
 * - Request-level state: Controllers, request data, session (cleared after each request)
 * - Request-scoped instances are stored in ApplicationState and cleared automatically
 * - The handle() method creates fresh ApplicationState for each request
 * - Controller instances are cached per-request, not across requests
 * - Dependency resolution is stateless (no instance-level $resolving array)
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class Kernel implements AppInterface
{
    use AppSecurity;

    public const VERSION = 'dev';

    // Core
    public string $baseDir;
    public LoaderInterface $routeLoader;
    protected LoggerInterface $logger;
    protected array $authenticators = [];
    protected array $controllers = [];
    protected array $factories = [];
    protected array $fileMap = [];
    protected array $instances = [];
    protected array $repositories = [];

    // Lazily instantiated dependencies
    protected ?EmitterInterface $emitter = null;
    protected ?Environment $environment = null;
    protected ?EntityManagerFactory $entityManagerFactory = null;
    protected ?ExceptionHandler $exceptionHandler = null;
    protected ?ParameterResolverInterface $parameterResolver = null;
    protected ?PrepareResponseInterface $prepareResponse = null;
    protected ?RouterInterface $router = null;
    protected ?SerializerInterface $serializer = null;
    protected ?ValidatorInterface $validator = null;
    protected ParameterBag $parameterBag;
    protected array $interfaceMap = [];

    // Security components
    protected array $firewallConfig = [];
    public ?array $accessControlRules = null;
    public ?RoleHierarchy $roleHierarchy = null;

    // Request-scoped state (created per request in handle())
    protected ?ApplicationStateInterface $state = null;
    public DebugStack $debugStack;

    // Router configuration
    protected array $routerOptions = [];
    protected mixed $routeResource = null;

    public function boot(): self
    {
        $this->parameterBag = new ParameterBag();
        $this->debugStack = new DebugStack();
        $this->routeResource = 'routes.php';
        $this->interfaceMap = require $this->fileMap['interfaces'];

        $this->setRouterOptions([
            'cache_dir' => $this->environment()->isProd() ? $this->baseDir . '/var/cache/router' : null,
            'debug' => $this->environment()->isDev(),
            'resource_type' => null,
            'strict_requirements' => true,
        ]);

        return $this;
    }

    // ============================================================================
    // ABSTRACT — implement in your concrete application class
    // ============================================================================

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    abstract public function reset(): void;

    abstract public function serializer(): SerializerInterface;

    abstract public function parameterResolver(): ParameterResolverInterface;

    abstract public function validator(): ValidatorInterface;

    abstract public function userProvider(): UserProviderInterface;

    // ============================================================================
    // ROUTING & CONTROLLER RESOLUTION
    // ============================================================================

    /**
     * Resolve the controller for the current request and execute it.
     * This handles:
     * 1. Access control enforcement
     * 2. Route matching
     * 3. Controller instantiation
     * 4. Parameter resolution
     * 5. Controller method execution
     *
     * @throws \ReflectionException
     */
    public function controllerResolver(ServerRequestInterface $request): ResponseInterface
    {
        $this->enforceAccessControl($request);

        $parameters = $this->router()->match($request);

        $controller = $parameters['_controller'] ?? null;

        if ($controller === null) {
            throw new ResourceNotFoundException('No controller found for request');
        }

        $this->enforceAttributeAccessControl($parameters);

        if (!is_array($controller) || count($controller) !== 2) {
            throw new \InvalidArgumentException('One of the routes does not have a valid controller definition. Expected format: [ClassName, methodName].');
        }

        foreach ($parameters as $key => $value) {
            if ($key[0] !== '_') {
                $request = $request->withAttribute($key, $value);
            }
        }

        [$class, $method] = $controller;
        $classObject = $this->getController($class);

        if (!method_exists($classObject, $method)) {
            throw new \InvalidArgumentException("Method $method does not exist in $class");
        }

        $reflection = new \ReflectionMethod($class, $method);
        $arg = $reflection->getParameters() === [] ? [] : $this->parameterResolver()->getParameters($reflection, [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $this,
            'firewall' => $this->getFirewallName($request->getUri()->getPath()),
            ...$parameters
        ], []);

        return call_user_func_array([$classObject, $method], $arg);
    }

    public function getController(string $id): object
    {
        // Check request-scoped cache first
        if ($this->state->hasRequestInstance($id)) {
            return $this->state->getRequestInstance($id);
        }

        $namedDependencies = $this->getControllerDependencies($id);

        if ($namedDependencies === []) {
            $controller = $this->instantiateController($id);
            $this->state->setRequestInstance($id, $controller);
            return $controller;
        }

        $resolved = $this->resolveDependencies($namedDependencies);
        $controller = $this->instantiateController($id, $resolved);
        $this->state->setRequestInstance($id, $controller);

        return $controller;
    }

    protected function getControllerDependencies(string $id): array
    {
        if (!isset($this->controllers[$id])) {
            $resolver = new ReflectionControllerArgumentResolver($this);
            return $resolver->resolveArguments($id);
        }

        return $this->controllers[$id];
    }

    protected function resolveDependencies(array $namedDependencies): array
    {
        $resolved = [];

        foreach ($namedDependencies as $key => $dep) {
            if (is_string($dep)) {
                $resolved[$key] = $this->resolveDependency($dep);
            } else {
                $resolved[$key] = $dep;
            }
        }

        return $resolved;
    }

    protected function resolveDependency(string $dep): mixed
    {
        if (str_starts_with($dep, '%') && str_ends_with($dep, '%')) {
            return $this->getParameter(trim($dep, '%'));
        }

        if (str_starts_with($dep, '@')) {
            $method = substr($dep, 1);
            if (!method_exists($this, $method)) {
                throw new \InvalidArgumentException("Service method '$method' not found.");
            }
            return $this->$method();
        }

        if (str_contains($dep, '\\')) {
            return $this->get($dep);
        }

        return $dep;
    }

    protected function instantiateController(string $id, array $resolved = []): object
    {
        $controller = new $id(...$resolved);

        if ($controller instanceof AbstractController) {
            $controller->setSubscribedServices($this);
        }

        return $controller;
    }

    // ============================================================================
    // CORE SERVICE ACCESSORS (can be overridden)
    // ============================================================================

    public function emitter(): EmitterInterface
    {
        return $this->emitter ??= new Emitter();
    }

    public function environment(): Environment
    {
        return $this->environment ??= Environment::from(env('APP_ENV', 'prod'));
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManagerFactory()->get();
    }

    private function entityManagerFactory(): EntityManagerFactory
    {
        return $this->entityManagerFactory ??= new EntityManagerFactory(
            baseDir: $this->baseDir,
            environment: $this->environment(),
            configuratorFactory: function ($configurator): void {
                $closure = require $this->fileMap['doctrine'];
                $closure($configurator);
            },
            debugStack: $this->debugStack,
            logger: $this->logger,
        );
    }

    public function exceptionHandler(): ExceptionHandlerInterface
    {
        return $this->exceptionHandler ??= new ExceptionHandler(
            $this->environment(),
            $this->logger ?? null
        );
    }


    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @throws Exception
     */
    public function prepareResponse(): PrepareResponseInterface
    {
        return $this->prepareResponse ??= new PrepareResponse();
    }

    public function router(): RouterInterface
    {
        return $this->router ??= new Router(
            $this->routeLoader,
            $this->routeResource,
            $this->routerOptions
        );
    }

    public function session(): FlashBagAwareSessionInterface
    {
        if ($this->state === null) {
            throw new \RuntimeException('Session is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getSession();
    }

    public function tokenStorage(): TokenStorageInterface
    {
        if ($this->state === null) {
            throw new \RuntimeException('TokenStorage is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getTokenStorage();
    }

    // ============================================================================
    // CONTAINER / DEPENDENCY INJECTION
    // ============================================================================

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \Exception
     * @throws Exception
     */
    public function get(string $id, ?string $interface = null): mixed
    {
        return $this->resolve($id, $interface, []);
    }

    /**
     * @throws NotFoundException
     * @throws Exception
     */
    protected function resolve(string $id, ?string $interface, array $resolving): mixed
    {
        if (isset($resolving[$id])) {
            throw new \RuntimeException("Circular dependency detected for class $id");
        }

        $resolving[$id] = true;

        try {
            if ($this->isKernelClass($id)) {
                throw new \LogicException(sprintf(
                    'Injecting "%s" (the kernel/app) as a dependency is not allowed. ' .
                    'Use specific service accessors instead (e.g. router(), serializer(), session()).',
                    $id
                ));
            }

            if (array_key_exists($id, $this->interfaceMap)) {
                $instance = $this->interfaceMap[$id]();
            } elseif (isset($this->instances[$id])) {
                $instance = $this->instances[$id];
            } elseif (array_key_exists($id, $this->repositories())) {
                $instance = $this->getRepository($id);
            } elseif (isset($this->authenticators[$id])) {
                $instance = $this->authenticators[$id]($this);
            } elseif (isset($this->factories[$id])) {
                $instance = $this->factories[$id]($this);
            } else {
                throw new NotFoundException("Class or parameter $id is not found.");
            }

            if ($interface && !$instance instanceof $interface) {
                throw new \RuntimeException(sprintf(
                    'Service "%s" does not implement required interface "%s".',
                    get_debug_type($instance),
                    $interface
                ));
            }

            return $instance;
        } catch (\Error $e) {
            if ($e instanceof \ArgumentCountError) {
                throw new \InvalidArgumentException(
                    \sprintf('Class "%s" has required constructor arguments that dont exist in container.', $id),
                    0,
                    $e
                );
            }
            throw $e;
        }
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
     * @throws Exception
     */
    public function has(string $id): bool
    {
        if ($this->isKernelClass($id)) {
            return false;
        }

        return isset($this->instances[$id]) ||
            array_key_exists($id, $this->interfaceMap) ||
            array_key_exists($id, $this->repositories()) ||
            isset($this->factories[$id]);
    }

    /**
     * @throws Exception
     */
    public function repositories(): array
    {
        return $this->repositories ??= $this->getRepositoriesAndEntities();
    }

    /**
     * @throws Exception
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
     * @throws Exception
     */
    public function getRepository(string $repositoryClass): object
    {
        $repositories = $this->repositories();

        if (!array_key_exists($repositoryClass, $repositories)) {
            throw new \InvalidArgumentException("Repository $repositoryClass not found.");
        }

        $entityClass = $repositories[$repositoryClass];
        return $this->entityManager()->getRepository($entityClass);
    }

    // ============================================================================
    // CONFIGURATION
    // ============================================================================

    public function configureFirewall(array $config): self
    {
        $this->firewallConfig = $config['firewalls'] ?? [];
        $this->accessControlRules = $config['access_control'] ?? [];
        $this->roleHierarchy = new RoleHierarchy($config['role_hierarchy'] ?? []);

        // Sync firewall config to application state if it exists
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    /**
     * Configure security using SecurityConfigurator (new fluent API)
     *
     * @param SecurityConfigurator $configurator
     * @return static
     */
    public function configureSecurity(SecurityConfigurator $configurator): static
    {
        $this->firewallConfig = $configurator->getFirewalls();
        $this->accessControlRules = $configurator->getAccessControlRules();
        $this->roleHierarchy = $configurator->getRoleHierarchy();
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    public function setRouterOptions(array $options): void
    {
        $defaultOptions = [
            'cache_dir' => null,
            'debug' => false,
            'resource_type' => null,
            'strict_requirements' => true,
        ];

        $invalid = [];
        foreach ($options as $key => $value) {
            if (\array_key_exists($key, $defaultOptions)) {
                $this->routerOptions[$key] = $value;
            } else {
                $invalid[] = $key;
            }
        }

        if ($invalid) {
            throw new \InvalidArgumentException(\sprintf('The Router does not support the following options: "%s".', implode('", "', $invalid)));
        }
    }

    // ============================================================================
    // UTILITIES
    // ============================================================================

    public function authenticators(): array
    {
        return $this->authenticators;
    }

    /**
     * Register or override an authenticator factory at runtime.
     *
     * Useful for tests that need a specific authenticator configuration
     * without modifying the global config file.
     */
    public function registerAuthenticator(string $name, \Closure $factory): static
    {
        $this->authenticators[$name] = $factory;
        return $this;
    }

    public function getFirewallName(string $path): ?string
    {
        if ($this->state === null) {
            throw new \RuntimeException('Firewall resolution is not available. ApplicationState must be initialized by handling a request first.');
        }
        return $this->state->getFirewallName($path);
    }

    public function getFirewallConfig(string $firewallName): array
    {
        return $this->firewallConfig[$firewallName] ?? [];
    }

    public function getParameterBag(): ParameterBag
    {
        return $this->parameterBag;
    }

    public function getParameter(string $name): array|bool|string|int|float|null
    {
        return $this->parameterBag->get($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->parameterBag->has($name);
    }

    public function setParameter(string $name, array|bool|string|int|float|null $value): void
    {
        $this->parameterBag->set($name, $value);
    }

    /**
     * Generate URL from route name and parameters
     */
    public function generateUrl(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->router()->generateUrl($name, $parameters, $referenceType);
    }

    public function urlGenerator(): UrlGeneratorInterface
    {
        return $this->router()->getUrlGenerator();
    }

    public function url(string $path = ''): string
    {
        $baseUrl = rtrim($this->baseUrl(), '/');
        $path = ltrim($path, '/');

        return $path === '' ? $baseUrl : $baseUrl . '/' . $path;
    }

    public function baseUrl(): string
    {
        return $this->state?->getBaseUrl() ?? '';
    }

    // ============================================================================
    // STATE MANAGEMENT & CLEANUP
    // ============================================================================

    public function getState(): ?ApplicationStateInterface
    {
        return $this->state;
    }

    public function request(): ServerRequestInterface
    {
        return $this->state->getRequest();
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->state->setRequest($request);

        return $this;
    }
}
