<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\DependencyInjection\ParameterBag;
use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Doctrine\EntityManagerFactory;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Exception\ExceptionHandlerInterface;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Routing\Router;
use Modufolio\Appkit\Routing\RouterInterface;
use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManager;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\FirewallConfiguration;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\UserChecker;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasher;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Emitter;
use Modufolio\Psr7\Http\EmitterInterface;
use Modufolio\Psr7\Http\Factory\Psr17Factory;
use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
    protected ?string $varDir = null;
    public LoaderInterface $routeLoader;
    protected LoggerInterface $logger;
    /** @var array<string, \Closure> */
    protected array $authenticators = [];

    /** @var array<class-string, array<int|string, mixed>> */
    protected array $controllers = [];

    /** @var array<string, \Closure> */
    protected array $factories = [];

    /** @var array<string, string> */
    protected array $fileMap = [];

    /** @var array<string, object> */
    protected array $instances = [];

    /** @var array<class-string, class-string>|null */
    protected ?array $repositories = null;

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
    /** @var array<class-string, \Closure> */
    protected array $interfaceMap = [];
    /** @var array<string, \Closure> Definitions from config/services.php via configureServices() */
    protected array $services = [];
    /** @var array<string, true> Service ids resolved once per request, cached in the instance table */
    protected array $sharedServices = [];
    private ?ContainerInterface $fallbackContainer = null;

    // Security components
    /** @var array<string, array<string, mixed>> */
    protected array $firewallConfig = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $accessControlRules = null;
    public ?RoleHierarchy $roleHierarchy = null;
    protected bool $denyUnmatchedAccess = false;
    protected ?AccessDecisionEngine $accessDecisionEngine = null;

    // Request-scoped state (created per request in handle())
    protected ?ApplicationStateInterface $state = null;
    public DebugStack $debugStack;

    // Router configuration
    /** @var array<string, mixed> */
    protected array $routerOptions = [];
    protected mixed $routeResource = null;

    public function boot(): static
    {
        // Dev throws warnings as exceptions; prod forces display_errors off for
        // web SAPIs. Test is left alone so PHPUnit keeps its own error handling.
        if ($this->environment()->isDev()) {
            Debug::enable();
        } elseif ($this->environment()->isProd()) {
            Debug::harden();
        }

        $this->parameterBag = new ParameterBag();
        $this->debugStack = new DebugStack();
        $this->routeResource = 'routes.php';
        // Legacy interfaces.php file, when mapped; otherwise the kernel wires
        // its own core services and config/services.php supplies the rest.
        $this->interfaceMap = isset($this->fileMap['interfaces'])
            ? require $this->fileMap['interfaces']
            : $this->coreServices();

        $this->setRouterOptions([
            'cache_dir' => $this->environment()->isProd() ? $this->cacheDir().'/router' : null,
            'debug' => $this->environment()->isDev(),
            'resource_type' => null,
            'strict_requirements' => true,
        ]);

        // Lock the token unserialize whitelist. Consumers register their User
        // entity (and other token-nested classes) before calling boot(); after
        // this point no further classes can be added, narrowing the gadget
        // surface in case post-boot code is ever loaded with attacker control.
        TokenUnserializer::freeze();

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
     * 5. Controller method execution.
     *
     * @throws \ReflectionException
     */
    public function controllerResolver(ServerRequestInterface $request): ResponseInterface
    {
        $this->enforceAccessControl($request);

        $parameters = $this->router()->match($request);

        $controller = $parameters['_controller'] ?? null;

        if (null === $controller) {
            throw new ResourceNotFoundException('No controller found for request');
        }

        $this->enforceAttributeAccessControl($parameters, $request);

        if (!is_array($controller) || 2 !== count($controller)) {
            throw new \InvalidArgumentException('One of the routes does not have a valid controller definition. Expected format: [ClassName, methodName].');
        }

        foreach ($parameters as $key => $value) {
            if ('_' === $key[0]) {
                continue;
            }

            $request = $request->withAttribute($key, $value);
        }

        [$class, $method] = $controller;

        if (!is_string($class) || !is_string($method)) {
            throw new \InvalidArgumentException('One of the routes does not have a valid controller definition. Expected format: [ClassName, methodName].');
        }

        $classObject = $this->getController($class);

        if (!method_exists($classObject, $method)) {
            throw new \InvalidArgumentException("Method {$method} does not exist in {$class}");
        }

        $reflection = new \ReflectionMethod($class, $method);
        $arg = [] === $reflection->getParameters() ? [] : $this->parameterResolver()->getParameters($reflection, [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $this,
            'firewall' => $this->getFirewallNameForRequest($request),
            ...$parameters,
        ], []);

        // Spread by name, not by position: the resolvers key their results by
        // parameter name and fill them in resolver order, which is not the
        // order of the method signature. array_values() would hand a route
        // parameter to the first argument whenever a resolver later in the
        // pipeline supplied an earlier parameter.
        return $classObject->{$method}(...$arg);
    }

    /**
     * Prime request-scoped state with a synthetic request, for callers that
     * need the container (e.g. getController()) outside of a real HTTP
     * request/response cycle — CLI commands and test suites.
     *
     * Idempotent: a request already primed by handle() (or a prior call) is
     * left untouched.
     */
    public function initializeConsoleState(): static
    {
        if (null === $this->state) {
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

            $this->state = new NativeApplicationState($request, $this->baseDir, $this->firewallConfig, $this->varDir());
        }

        return $this;
    }

    public function getController(string $id): object
    {
        if (!class_exists($id)) {
            throw new \InvalidArgumentException(sprintf('Controller class "%s" does not exist.', $id));
        }

        // Check request-scoped cache first
        if ($this->state()->hasRequestInstance($id)) {
            return $this->state()->getRequestInstance($id);
        }

        $namedDependencies = $this->getControllerDependencies($id);

        if ([] === $namedDependencies) {
            $controller = $this->instantiateController($id);
            $this->state()->setRequestInstance($id, $controller);

            return $controller;
        }

        $resolved = $this->resolveDependencies($namedDependencies);
        $controller = $this->instantiateController($id, $resolved);
        $this->state()->setRequestInstance($id, $controller);

        return $controller;
    }

    /**
     * @param class-string $id
     *
     * @return array<int|string, mixed>
     */
    protected function getControllerDependencies(string $id): array
    {
        if (!isset($this->controllers[$id])) {
            // Safety net, not a wiring strategy: log so the miss is visible
            // instead of silently resolving by reflection on every request.
            $this->logger()->warning(sprintf(
                'Controller "%s" is not wired in config/controllers.php — resolving its constructor by reflection. Wire it explicitly; a parameter with a default value silently receives that default instead of the wired service.',
                $id,
            ));

            $resolver = new ReflectionControllerArgumentResolver($this);

            return $resolver->resolveArguments($id);
        }

        return $this->controllers[$id];
    }

    /**
     * @param array<int|string, mixed> $namedDependencies
     *
     * @return array<int|string, mixed>
     */
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
                throw new \InvalidArgumentException("Service method '{$method}' not found.");
            }

            return $this->$method();
        }

        if (str_contains($dep, '\\')) {
            return $this->get($dep);
        }

        return $dep;
    }

    /**
     * @param array<int|string, mixed> $resolved
     */
    protected function instantiateController(string $id, array $resolved = []): object
    {
        $controller = new $id(...$resolved);

        if ($controller instanceof AppAwareInterface) {
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

    /**
     * The writable runtime directory. Defaults to $baseDir/var.
     */
    public function varDir(): string
    {
        return $this->varDir ??= $this->baseDir.'/var';
    }

    /**
     * The cache directory, namespaced by environment (var/cache/prod,
     * var/cache/dev, …) so switching APP_ENV on one machine can never serve a
     * cache built by another environment.
     */
    public function cacheDir(): string
    {
        return $this->varDir().'/cache/'.$this->environment()->value;
    }

    /**
     * Point sessions, caches and proxies at a different writable directory.
     *
     * Must be called before the first request is handled.
     */
    public function setVarDir(string $varDir): static
    {
        $this->varDir = rtrim($varDir, '/');

        return $this;
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
            varDir: $this->varDir(),
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
        if (null === $this->state) {
            throw new \RuntimeException('Session is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getSession();
    }

    public function tokenStorage(): TokenStorageInterface
    {
        if (null === $this->state) {
            throw new \RuntimeException('TokenStorage is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getTokenStorage();
    }

    /**
     * The manager is a thin stateless wrapper over the session (tokens live in
     * the session itself), so constructing one per call is safe in long-running
     * workers — no request-scoped state is cached across requests. Applications
     * that want a memoized instance override this and reset it themselves.
     */
    public function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return new CsrfTokenManager($this->session());
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
     * @throws Exception
     */
    protected function resolve(string $id, ?string $interface, array $resolving): mixed
    {
        if (isset($resolving[$id])) {
            throw new \RuntimeException("Circular dependency detected for class {$id}");
        }

        $resolving[$id] = true;

        try {
            if ($this->isKernelClass($id)) {
                throw new \LogicException(sprintf('Injecting "%s" (the kernel/app) as a dependency is not allowed. Use specific service accessors instead (e.g. router(), serializer(), session()).', $id));
            }

            if (isset($this->services[$id])) {
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
                throw new NotFoundException("Class or parameter {$id} is not found.");
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
     * @throws Exception
     */
    public function repositories(): array
    {
        return $this->repositories ??= $this->getRepositoriesAndEntities();
    }

    /**
     * @return array<class-string, class-string>
     *
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
            throw new \InvalidArgumentException("Repository {$repositoryClass} not found.");
        }

        $entityClass = $repositories[$repositoryClass];

        return $this->entityManager()->getRepository($entityClass);
    }

    // ============================================================================
    // CONFIGURATION
    // ============================================================================

    /**
     * @param array<string, mixed> $config
     */
    public function configureFirewall(array $config): self
    {
        $this->assertValidFirewallConfig($config['firewalls'] ?? []);
        $this->firewallConfig = $config['firewalls'] ?? [];
        $this->accessControlRules = $config['access_control'] ?? [];
        $this->roleHierarchy = new RoleHierarchy($config['role_hierarchy'] ?? []);
        $this->denyUnmatchedAccess = (bool) ($config['deny_unmatched'] ?? false);
        $this->accessDecisionEngine = null;

        // Sync firewall config to application state if it exists
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    /**
     * Configure security using SecurityConfigurator (new fluent API).
     */
    public function configureSecurity(SecurityConfigurator $configurator): static
    {
        $this->assertValidFirewallConfig($configurator->getFirewalls());
        $this->firewallConfig = $configurator->getFirewalls();
        $this->accessControlRules = $configurator->getAccessControlRules();
        $this->roleHierarchy = $configurator->getRoleHierarchy();
        $this->denyUnmatchedAccess = $configurator->deniesUnmatchedRequests();
        $this->accessDecisionEngine = null;
        $this->state?->setFirewallConfig($this->firewallConfig);

        return $this;
    }

    /**
     * Validate firewall configuration against the FirewallConfiguration schema.
     *
     * Type-checks the keys appkit itself consumes and rejects `methods` — a key
     * that reads as a per-method firewall filter but is silently ignored by
     * firewall selection (which matches on pattern alone), so it fails open.
     * App-specific keys the schema does not know are passed through untouched,
     * since firewall config is handed to the app's own ApplicationState.
     *
     * Skipped in prod. Config configuration runs on every request in appkit's
     * per-request boot, and building + normalizing the schema tree is not free;
     * paying that on every production hit to re-check config that has not
     * changed since deploy mirrors nothing Symfony does — Symfony validates at
     * container compile time and serves a cached, pre-validated result at
     * runtime. Here the equivalent is to validate in dev/test (and CI), where
     * the config is authored and the failure is wanted loud and immediate, and
     * to trust the already-validated config in prod. Deploys that never run
     * dev/test should validate in CI (the schema is public: run the Processor
     * against FirewallConfiguration there).
     *
     * @param array<string, array<string, mixed>> $firewalls
     *
     * @throws \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    private function assertValidFirewallConfig(array $firewalls): void
    {
        if ($this->environment()->isProd()) {
            return;
        }

        (new Processor())->processConfiguration(
            new FirewallConfiguration(),
            [['firewalls' => $firewalls]],
        );
    }

    /**
     * @param array<string, mixed> $options
     */
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

    /**
     * @return array<string, \Closure>
     */
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
        if (null === $this->state) {
            throw new \RuntimeException('Firewall resolution is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getFirewallName($path);
    }

    /**
     * Resolve the firewall for a request, honouring pattern + methods + host +
     * ips restrictions (Symfony-style). Security-critical selection uses this.
     */
    public function getFirewallNameForRequest(ServerRequestInterface $request): ?string
    {
        if (null === $this->state) {
            throw new \RuntimeException('Firewall resolution is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getFirewallNameForRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFirewallConfig(string $firewallName): array
    {
        return $this->firewallConfig[$firewallName] ?? [];
    }

    /**
     * All configured firewalls, keyed by name, in declaration order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFirewalls(): array
    {
        return $this->firewallConfig;
    }

    /**
     * The configured access-control rules, in declaration order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAccessControlRules(): array
    {
        return $this->accessControlRules ?? [];
    }

    public function getRoleHierarchy(): ?RoleHierarchy
    {
        return $this->roleHierarchy;
    }

    /**
     * The engine enforcing access-control rules and #[IsGranted] attributes.
     *
     * Built lazily from the configured rules and role hierarchy; rebuilt when
     * configureFirewall()/configureSecurity() replaces the configuration.
     * Register custom rule constraints on it during application setup:
     *
     *   $app->accessDecisionEngine()->registerConstraint(new OfficeHoursConstraint());
     */
    public function accessDecisionEngine(): AccessDecisionEngine
    {
        return $this->accessDecisionEngine ??= new AccessDecisionEngine(
            rules: $this->accessControlRules ?? [],
            roleHierarchy: $this->roleHierarchy,
            denyByDefault: $this->denyUnmatchedAccess,
        );
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

    /**
     * Generate URL from route name and parameters.
     *
     * @param array<string, mixed> $parameters
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

        return '' === $path ? $baseUrl : $baseUrl.'/'.$path;
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
     * Request-scoped state, which only exists once a request is being handled.
     *
     * @throws \LogicException when called outside a request
     */
    protected function state(): ApplicationStateInterface
    {
        if (null === $this->state) {
            throw new \LogicException('No request-scoped state is available. Call handle() or initializeConsoleState() first.');
        }

        return $this->state;
    }

    public function request(): ServerRequestInterface
    {
        return $this->state()->getRequest();
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->state()->setRequest($request);

        return $this;
    }
}
