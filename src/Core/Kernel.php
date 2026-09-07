<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Attributes\Service;
use Modufolio\Appkit\Debug\NullProfiler;
use Modufolio\Appkit\Debug\ProfilerInterface;
use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\DependencyInjection\ParameterBag;
use Modufolio\Appkit\Doctrine\EntityManagerFactory;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Exception\ExceptionHandlerInterface;
use Modufolio\Appkit\Http\TrustedHosts;
use Modufolio\Appkit\Inertia\InertiaModule;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Module\ModuleInterface;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Routing\RouterInterface;
use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManager;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Emitter;
use Modufolio\Psr7\Http\EmitterInterface;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
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
 * - The resolving stack empties with every resolution (finally-cleared), so
 *   no per-request resolution state survives into the next request
 *
 * The kernel keeps state, lifecycle and the service accessors; behavior is
 * composed from traits, one per concern: AppContainer (resolution and
 * parameters), AppControllers (controller wiring), AppModules (module
 * lifecycle), AppRouting (router and URLs), AppSecurity (auth flow and
 * firewall configuration). Every property the traits touch is declared here.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class Kernel implements AppInterface
{
    use AppContainer;
    use AppControllers;
    use AppModules;
    use AppRouting;
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
    /** @var list<ModuleInterface> Modules from config/modules.php via configureModules() */
    protected array $modules = [];
    /** @var array<string, string> Service id => module name, for diagnostics on module-registered ids */
    protected array $serviceProvenance = [];
    /** @var array<string, true>|null Methods injectable via '@name' — the #[Service] allowlist, reflected once per process */
    protected ?array $serviceMethods = null;
    /** @var array<string, string> Deprecated service id => message, triggered once per process on first resolve */
    protected array $deprecatedServices = [];
    /** @var array<string, true> Deprecated ids already warned about this process */
    private array $triggeredDeprecations = [];
    /** @var array<string, true> Ids currently being resolved, in resolution order — powers circular-chain errors */
    private array $resolvingStack = [];
    /** @var list<callable(bool): void> One-shot reset callbacks registered by service factories; run and cleared by resetModules() */
    private array $resetCallbacks = [];
    /** @var array<string, true> Service ids resolved once per request, cached in the instance table */
    protected array $sharedServices = [];
    private ?ContainerInterface $fallbackContainer = null;
    /** Builds the fallback container during boot(), when set by configureContainer() */
    private ?ContainerFactoryInterface $containerFactory = null;

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
    /** Timeline of the request: the kernel records its own phases, a profiler reads them */
    protected ?Stopwatch $stopwatch = null;
    /** The profiling seam; NullProfiler until config/services.php or a module declares one */
    protected ?ProfilerInterface $profiler = null;

    // Router configuration
    /** @var array<string, mixed> */
    protected array $routerOptions = [];
    protected mixed $routeResource = null;
    /** Built from the "trusted_hosts" router option; see AppRouting::trustedHosts() */
    protected ?TrustedHosts $trustedHosts = null;

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
        // Query origins cost a backtrace per query: dev only.
        $this->debugStack = new DebugStack(collectOrigin: $this->environment()->isDev());
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

        // Every '@' reference in the controller map (application and modules
        // alike) must name a #[Service] method — checked here so all mistakes
        // surface at boot, not one request at a time.
        $this->validateControllerDependencies();

        // Modules boot last, once the kernel's own setup is in place, so their
        // boot() can reach core services. Each module's merged config is
        // published as the "module.<name>" parameter first.
        foreach ($this->modules as $module) {
            $this->parameterBag->set('module.'.$module->name(), $module->config());
        }

        // The optional second container is built here, not in
        // configureContainer(): it reads the parameter bag and the module
        // parameters, which only exist now, and a module's boot() below may
        // already reach into it.
        if (null !== $this->containerFactory) {
            $this->setFallbackContainer($this->containerFactory->create($this));
        }

        foreach ($this->modules as $module) {
            $module->boot($this);
        }

        // Lock the token unserialize whitelist. Consumers register their User
        // entity (and other token-nested classes) before calling boot(); after
        // this point no further classes can be added, narrowing the gadget
        // surface in case post-boot code is ever loaded with attacker control.
        // Modules may still register classes from boot(), which runs above.
        TokenUnserializer::freeze();

        return $this;
    }

    /**
     * Opt in to a second container behind this one — the Symfony container,
     * through {@see \Modufolio\Appkit\DependencyInjection\Symfony\ContainerFactory},
     * or any PSR-11 container a factory adapts.
     *
     * The kernel keeps answering every id it declares itself; only an id it
     * does not know reaches the fallback. Call before boot(), after
     * configureModules() and configureServices(): the factory runs during
     * boot() so it can see the finished declarations, the parameter bag and
     * each module's published configuration.
     */
    public function configureContainer(ContainerFactoryInterface $factory): static
    {
        $this->containerFactory = $factory;

        return $this;
    }

    /**
     * The modules loaded by configureModules(), in manifest order.
     *
     * @return list<ModuleInterface>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Every id this container answers by its own declarations: the core
     * services, config/services.php and module definitions, the configured
     * repositories and any legacy factories. What a container behind this one
     * needs in order to expose the kernel's services to its own graph.
     *
     * Repositories discovered lazily from the entity metadata are not listed
     * — that would build the entity manager at boot — so a fallback container
     * reaches them through EntityManagerInterface instead.
     *
     * @return list<string>
     */
    public function declaredServiceIds(): array
    {
        return array_values(array_unique([
            ...array_keys($this->services),
            ...array_keys($this->interfaceMap),
            ...array_keys($this->repositories ?? []),
            ...array_keys($this->factories),
        ]));
    }

    /**
     * The #[Service] accessors on this application whose return type is a
     * class or interface not already declared as a service id, keyed by that
     * type: `[ThumbnailGenerator::class => 'thumbnailGenerator']`. A fallback
     * container registers each under its type so the accessor answers an
     * autowired argument, the way the '@method' controller form does here.
     *
     * @return array<class-string, string>
     */
    public function serviceAccessors(): array
    {
        $declared = array_fill_keys($this->declaredServiceIds(), true);
        $accessors = [];

        foreach (array_keys($this->serviceMethods()) as $method) {
            $type = (new \ReflectionMethod($this, $method))->getReturnType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $name */
            $name = $type->getName();

            if (!isset($declared[$name]) && !isset($accessors[$name]) && !$this->isKernelClass($name)) {
                $accessors[$name] = $method;
            }
        }

        return $accessors;
    }

    // ============================================================================
    // ABSTRACT — implement in your concrete application class
    // ============================================================================

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    abstract public function reset(): void;

    #[Service]
    abstract public function serializer(): SerializerInterface;

    #[Service]
    abstract public function parameterResolver(): ParameterResolverInterface;

    #[Service]
    abstract public function validator(): ValidatorInterface;

    #[Service]
    abstract public function userProvider(): UserProviderInterface;

    // ============================================================================
    // CORE SERVICE ACCESSORS (can be overridden)
    // ============================================================================

    #[Service]
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

    #[Service]
    public function environment(): Environment
    {
        return $this->environment ??= Environment::from(env('APP_ENV', 'prod'));
    }

    #[Service]
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

    #[Service]
    public function exceptionHandler(): ExceptionHandlerInterface
    {
        return $this->exceptionHandler ??= new ExceptionHandler(
            $this->environment(),
            $this->logger ?? null
        );
    }

    #[Service]
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    #[Service]
    public function prepareResponse(): PrepareResponseInterface
    {
        return $this->prepareResponse ??= new PrepareResponse($this->profiler());
    }

    /**
     * The request timeline. The kernel starts and stops an event around each
     * phase it owns — security.session, security.authenticate, routing,
     * security.access_control, controller — and a profiler reads the events;
     * application code may add its own around anything it wants to see there.
     * Reset between requests by resetModules().
     */
    #[Service]
    public function stopwatch(): Stopwatch
    {
        return $this->stopwatch ??= new Stopwatch(true);
    }

    /**
     * The profiler the response goes through at the end of every request:
     * a NullProfiler until the application (or a module, from boot()) installs
     * one with setProfiler(), or the App overrides this accessor. Like every
     * core accessor, a config/services.php entry for ProfilerInterface changes
     * what get() hands to controllers, not what the kernel itself calls.
     */
    #[Service]
    public function profiler(): ProfilerInterface
    {
        return $this->profiler ??= new NullProfiler();
    }

    /**
     * Install the profiler. Modules call this from boot(); the application
     * factory can call it after boot(). Takes effect for the next request.
     */
    public function setProfiler(ProfilerInterface $profiler): static
    {
        $this->profiler = $profiler;
        // The response preparer captured the previous profiler; rebuild lazily.
        $this->prepareResponse = null;

        return $this;
    }

    #[Service]
    public function session(): FlashBagAwareSessionInterface
    {
        if (null === $this->state) {
            throw new \RuntimeException('Session is not available. ApplicationState must be initialized by handling a request first.');
        }

        return $this->state->getSession();
    }

    #[Service]
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
    #[Service]
    public function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return new CsrfTokenManager($this->session());
    }

    /**
     * What finishes the Inertia pages controllers return: the host wires it
     * by listing {@see InertiaModule} in
     * config/modules.php (or declaring InertiaRenderer in config/services.php).
     */
    #[Service]
    public function inertia(): InertiaRenderer
    {
        if (!$this->has(InertiaRenderer::class)) {
            throw new \LogicException(sprintf('A controller returned an Inertia page, but Inertia is not wired: list %s in config/modules.php, or declare %s in config/services.php.', InertiaModule::class, InertiaRenderer::class));
        }

        return $this->get(InertiaRenderer::class, InertiaRenderer::class);
    }

    // ============================================================================
    // STATE MANAGEMENT
    // ============================================================================

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

    /**
     * Build the request-scoped state for an incoming request.
     *
     * This is the intended first step of handle(): the request's Host header is
     * checked against the trusted-hosts allowlist *before* the state exists, so
     * an untrusted host is rejected (400) without ever being copied into the
     * base URL that url(), templates and absolute route generation build on.
     *
     *     public function handle(ServerRequestInterface $request): ResponseInterface
     *     {
     *         $this->state?->reset();
     *         try {
     *             $this->state = $this->createState($request);
     *             $response = $this->handleAuthentication($request);
     *         } catch (\Throwable $e) {
     *             $response = $this->exceptionHandler()->handle($e, $request);
     *         }
     *         ...
     *     }
     *
     * @throws \Modufolio\Appkit\Exception\UntrustedHostException
     */
    protected function createState(ServerRequestInterface $request): ApplicationStateInterface
    {
        $this->assertTrustedHost($request);

        // The timeline is request-scoped like the state: a new request starts
        // it empty, whether the runtime called reset() in between.
        $this->stopwatch?->reset();

        return new NativeApplicationState($request, $this->baseDir, $this->firewallConfig, $this->varDir());
    }

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
