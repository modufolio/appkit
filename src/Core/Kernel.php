<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\DependencyInjection\ParameterBag;
use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use Modufolio\Appkit\Doctrine\ConnectionOptimizer;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugMiddleware;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\OrmConfigurator;
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
use DI\Container;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Modufolio\Psr7\Http\Emitter;
use Modufolio\Psr7\Http\EmitterInterface;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use function DI\factory;
use function DI\value;

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

    public const VERSION = '0.0.7';

    // Lazily instantiated dependencies
    protected ?Connection $connection = null;
    protected ?EmitterInterface $emitter = null;
    protected ?EntityManagerInterface $entityManager = null;
    protected ?Environment $environment = null;
    protected ?ExceptionHandler $exceptionHandler = null;
    protected ?ParameterResolverInterface $parameterResolver = null;
    protected ?PrepareResponseInterface $prepareResponse = null;
    protected ?RouterInterface $router = null;
    protected ?SerializerInterface $serializer = null;
    protected ?ValidatorInterface $validator = null;
    protected ParameterBag $parameterBag;
    protected array $interfaceMap = [];
    protected ?Container $phpDiContainer = null;

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

    /**
     * @throws \Exception
     */
    public function __construct(
        public string $baseDir,
        public LoaderInterface $routeLoader,
        protected array $authenticators = [],
        protected array $controllers = [],
        protected array $factories = [],
        protected array $fileMap = [],
        protected array $instances = [],
        protected array $repositories = []
    ) {
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

        $this->initializePhpDi();
    }

    /**
     * Initialize PHP-DI container for auto-wiring fallback.
     *
     * @throws \Exception
     */
    protected function initializePhpDi(): void
    {
        $builder = new ContainerBuilder();

        // Enable compilation in production for zero overhead
        if ($this->environment()->isProd()) {
            $builder->enableCompilation($this->baseDir . '/var/cache/di');
            $builder->writeProxiesToFile(true, $this->baseDir . '/var/cache/proxies/di');
        }

        // Configure definitions for special cases
        $builder->addDefinitions([
            // Container itself - delegate to custom container
            ContainerInterface::class => value($this),
            static::class => value($this),

            // Application-scoped services from custom container
            EntityManagerInterface::class => factory(fn (Kernel $kernel) => $kernel->entityManager()),
            Connection::class => factory(fn (Kernel $kernel) => $kernel->connection ?? $kernel->entityManager()->getConnection()),
            RouterInterface::class => factory(fn (Kernel $kernel) => $kernel->router()),
            SerializerInterface::class => factory(fn (Kernel $kernel) => $kernel->serializer()),
            ValidatorInterface::class => factory(fn (Kernel $kernel) => $kernel->validator()),

            // Note: Request-scoped services (ServerRequestInterface, SessionInterface, TokenStorageInterface)
            // are set synthetically via set() in handle() method at the start of each request
        ]);

        $this->phpDiContainer = $builder->build();
    }

    // ============================================================================
    // ENTRY POINT & REQUEST HANDLING
    // ============================================================================

    /**
     * Handle an incoming request through the middleware pipeline.
     * This is the core request handler that:
     * 1. Creates fresh application state
     * 2. Runs middleware (maintenance, rate limiting, authentication)
     * 3. Prepares the response
     *
     * @throws Exception
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->state?->reset();

        unset($this->state);

        // Create fresh application state for this request
        $this->state = new NativeApplicationState($request, $this->firewallConfig);

        // Set all request-scoped services as synthetic services in PHP-DI
        if ($this->phpDiContainer !== null) {
            $this->phpDiContainer->set(ServerRequestInterface::class, $request);
            $this->phpDiContainer->set(FlashBagAwareSessionInterface::class, $this->state->getSession());
            $this->phpDiContainer->set(SessionInterface::class, $this->state->getSession());
            $this->phpDiContainer->set(TokenStorageInterface::class, $this->state->getTokenStorage());
        }

        try {
            $response = $this->handleAuthentication($request);
        } catch (\Throwable $e) {
            $response = $this->exceptionHandler()->handle($e, $request);
        }

        return $this->prepareResponse()->prepare($request, $response);
    }

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

    /**
     * @throws Exception
     */
    public function entityManager(): EntityManagerInterface
    {
        if ($this->entityManager && $this->entityManager->isOpen()) {
            return $this->entityManager;
        }

        $configurator = new OrmConfigurator();

        $closure = require $this->fileMap['doctrine'];
        $closure($configurator, $this);

        $cache = $this->environment()->isDev()
            ? new ArrayAdapter()
            : new FilesystemAdapter('doctrine', 0, $this->baseDir . '/var/cache');

        $config = $configurator->ormConfig;
        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);
        $config->setResultCache($cache);
        $config->setProxyDir($this->baseDir . '/var/proxies');
        $config->setProxyNamespace('DoctrineProxies');
        $config->setAutoGenerateProxyClasses(true);
        $config->setMetadataDriverImpl(new AttributeDriver($configurator->entityPaths));

        $dbalConfig = $configurator->dbalConfig;
        $dbalConfig->setMiddlewares([new DebugMiddleware($this->debugStack)]);

        $connection = DriverManager::getConnection($configurator->connectionParams, $configurator->dbalConfig);

        if ($configurator->optimizeConnection !== false) {
            $optimizer = new ConnectionOptimizer(
                $this->environment()->isDev(),
                $this->logger ?? null
            );
            $optimizer->optimize($connection);
        }

        $this->connection = $connection;

        $this->entityManager = new EntityManager($connection, $config);

        // Register event subscribers
        $eventManager = $this->entityManager->getEventManager();
        foreach ($configurator->getSubscribers() as $subscriber) {
            $eventManager->addEventSubscriber($subscriber);
        }

        return $this->entityManager;
    }

    public function exceptionHandler(): ExceptionHandlerInterface
    {
        return $this->exceptionHandler ??= new ExceptionHandler($this->environment());
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

    abstract public function serializer(): SerializerInterface;

    abstract public function parameterResolver(): ParameterResolverInterface;

    abstract public function validator(): ValidatorInterface;

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
            if ($id === ContainerInterface::class || $id === static::class) {
                return $this;
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
            } elseif ($this->phpDiContainer !== null && $this->phpDiContainer->has($id)) {
                // PHP-DI fallback for auto-wiring
                $instance = $this->phpDiContainer->get($id);
                // Cache application-scoped services for future requests
                // Don't cache controllers (they're request-scoped in ApplicationState)
                if (!str_contains($id, 'Controller')) {
                    $this->instances[$id] = $instance;
                }
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
     * @throws Exception
     */
    public function has(string $id): bool
    {
        if ($id === ContainerInterface::class || $id === static::class) {
            return true;
        }

        return isset($this->instances[$id]) ||
            array_key_exists($id, $this->interfaceMap) ||
            array_key_exists($id, $this->repositories()) ||
            isset($this->factories[$id]) ||
            ($this->phpDiContainer !== null && $this->phpDiContainer->has($id));
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

    abstract function userProvider(): UserProviderInterface;


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

    /**
     *
     * @return self
     * @throws \RuntimeException if called outside test environment
     */
    public function initializeTestState(): self
    {
        if (!$this->environment()->isTest()) {
            throw new \RuntimeException('initializeTestState() can only be called in test environment');
        }

        if ($this->state === null) {
            // Create minimal test request
            $request = new ServerRequest(
                method: 'GET',
                uri: new Uri('http://127.0.0.1'),
                headers: [],
                body: Stream::create(''),
                version: '1.1',
                serverParams: [
                    'HTTP_HOST' => '127.0.0.1',
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/',
                    'SERVER_PROTOCOL' => 'HTTP/1.1',
                ]
            );


            $this->state = new NativeApplicationState($request, $this->firewallConfig);
        }

        return $this;
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

    public function reset(): void
    {
        // Reset request-scoped state (session, tokens, controllers, firewall cache)
        // This breaks circular references: Token -> User -> EntityManager -> Entities
        $this->state?->reset();
        $this->state = null;

        // Clear request-scoped synthetic services from PHP-DI
        if ($this->phpDiContainer !== null) {
            $this->phpDiContainer->set(ServerRequestInterface::class, null);
            $this->phpDiContainer->set(FlashBagAwareSessionInterface::class, null);
            $this->phpDiContainer->set(SessionInterface::class, null);
            $this->phpDiContainer->set(TokenStorageInterface::class, null);
        }

        $this->debugStack->resetQueries();

        // Close and reset entity manager to prevent connection leaks
        // This clears the identity map and breaks entity references
        if ($this->entityManager) {
            $this->entityManager->getConnection()->close();
            $this->entityManager->close();
            $this->entityManager = null;
        }

        $this->emitter = null;
        $this->environment = null;

        if ($this->router) {
            if ($this->router instanceof ResetInterface) {
                $this->router->reset();
            }
            $this->router = null;
        }

        $this->instances = [];
    }
}
