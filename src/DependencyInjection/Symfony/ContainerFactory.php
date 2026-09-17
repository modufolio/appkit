<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection\Symfony;

use Modufolio\Appkit\Core\Kernel;
use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\Toolkit\F;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\AddEventAliasesPass;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

/**
 * The Symfony container as the kernel's optional second layer.
 *
 * Opting in is one line in the application factory:
 *
 * ```php
 * $app->configureModules()
 *     ->configureServices($serviceConfigurator)
 *     ->configureContainer(new ContainerFactory())
 *     ->configureSecurity($securityConfigurator)
 *     ->boot();
 * ```
 *
 * From then on `config/container.php` declares services with the Symfony PHP
 * DSL — autowiring, `load()` over a directory, tags, compiler passes — and
 * every module contributes its own `config/container.php` (loaded here by
 * convention) plus a build() hook when it implements
 * {@see ContainerBuilderAwareInterface}, exactly as a bundle would. Nothing
 * on the kernel side, or in the module contract, changes: the kernel
 * still answers every id it declares itself, and only an unknown id reaches
 * the Symfony container. A controller the Symfony container knows is built
 * there (autowired) before the reflection fallback is considered.
 *
 * What the Symfony side sees of the kernel — the bridge:
 *
 *   - `appkit.kernel`: the kernel as a PSR-11 container, synthetic, set once
 *     the container exists. The escape hatch; not autowired by type.
 *   - every id the kernel declares (core services, config/services.php, module
 *     services, repositories) as a *non-shared* service whose factory asks
 *     the kernel, so a Symfony service that autowires EntityManagerInterface
 *     or SessionInterface receives whatever the kernel would hand out for the
 *     current request — sharing stays the kernel's decision.
 *   - every #[Service] accessor on the App class with a class return type,
 *     under that type, the same way: `thumbnailGenerator(): ThumbnailGenerator`
 *     answers an autowired ThumbnailGenerator argument.
 *
 * A definition in container.php with the same id as a bridged service wins
 * inside the Symfony graph; the kernel side is never affected.
 *
 * Lifetime: the kernel's reset() (via resetModules()) resets the Symfony
 * container between requests under a worker runtime, so a Symfony service
 * lives one request, like a shared() kernel service.
 *
 * Reachability: every definition is public by default, because the kernel
 * fetches by id (see {@see PublicServicesPass}); `public: false` keeps
 * Symfony's private default for everything except controllers.
 *
 * Compilation: outside prod the builder is compiled and used as the runtime
 * container, so a changed file is picked up on the next boot with no cache to
 * clear. In prod the container is dumped to var/cache/prod/container/ once
 * and loaded from there; pass `dump: true|false` to force either mode. A
 * hash of the resolved module set sits beside the dumped class and forces a
 * rebuild when the set changes, prod included — the kernel reads the
 * manifest live on every boot, and the two must never disagree.
 *
 * Tags the framework owns: `appkit.controller` keeps a definition reachable
 * as a controller under `public: false` ({@see PublicServicesPass});
 * `appkit.user_provider` elects the firewall's user provider
 * ({@see UserProviderPass}).
 *
 * Events: the container owns the dispatcher. It registers Symfony's
 * EventDispatcher as `event_dispatcher` (aliased to the PSR-14 interface)
 * unless container.php declares one itself, runs Symfony's
 * RegisterListenersPass over the `kernel.event_listener` and
 * `kernel.event_subscriber` tags, and autoconfigures the
 * {@see AsEventListener} attribute into the first of those. A listener is
 * then a class with an attribute and nothing else: no registration call, no
 * factory closure, and the wiring is resolved at compile time and baked into
 * the dumped container, so nothing is scanned at runtime. The kernel picks
 * that dispatcher up in {@see Kernel::eventDispatcher()}, which is what makes
 * the framework's own events reach an attributed listener.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ContainerFactory implements ContainerFactoryInterface
{
    public const KERNEL_ID = ContainerFactoryInterface::KERNEL_ID;

    /**
     * @param string                $file         the application's Symfony definitions, relative to the base directory
     * @param bool|null             $dump         write and load a compiled container class; null means "in prod only"
     * @param bool                  $public       make every definition public so the kernel can fetch it by id
     *                                            (see {@see PublicServicesPass}); false keeps Symfony's private
     *                                            default for everything but controllers
     * @param array<string, string> $eventAliases short event names mapped to the class a listener
     *                                            type-hints, for listeners tagged with a name rather
     *                                            than a class (Symfony's AddEventAliasesPass)
     */
    public function __construct(
        private readonly string $file = 'config/container.php',
        private readonly ?bool $dump = null,
        private readonly bool $public = true,
        private readonly array $eventAliases = [],
    ) {
    }

    public function create(Kernel $kernel): ContainerInterface
    {
        $debug = !$kernel->environment()->isProd();

        if (!($this->dump ?? !$debug)) {
            $builder = $this->build($kernel);
            $builder->compile();
            $builder->set(self::KERNEL_ID, $kernel);

            return $builder;
        }

        $dir = $kernel->cacheDir().'/container';
        // One class per cache location: a second application (or environment)
        // in the same process gets its own name instead of a redeclaration.
        $class = 'AppkitContainer_'.substr(md5($dir), 0, 12);
        $namespace = 'Modufolio\\Appkit\\Compiled';
        $path = $dir.'/'.$class.'.php';
        $cache = new ConfigCache($path, $debug);

        // Cache-coherence gate. The kernel reads config/modules.php live on
        // every boot — module services, routes, entity paths — while the
        // dumped container baked the module definitions in once, and in prod
        // ConfigCache never re-checks its resources. A changed module set
        // would leave the two halves disagreeing (split-brain), so a hash of
        // the resolved manifest sits beside the class and forces a rebuild
        // whenever it moves, in every environment.
        $manifest = $this->manifestHash($kernel);
        $manifestFile = $path.'.manifest';
        $fresh = $this->isFresh($cache, $manifestFile, $manifest);

        if (!$fresh) {
            // One process compiles, the rest wait and load what it wrote —
            // otherwise a cold pool compiles the whole graph N times.
            $lock = @fopen($path.'.lock', 'w+');

            if (false !== $lock && !flock($lock, \LOCK_EX | \LOCK_NB)) {
                // Someone is compiling. Wait for them, then look again
                // instead of repeating the work they just finished.
                flock($lock, \LOCK_SH);
                $fresh = $this->isFresh($cache, $manifestFile, $manifest);
            }

            try {
                if (!$fresh) {
                    $builder = $this->build($kernel);
                    $builder->compile();
                    $code = (new PhpDumper($builder))->dump([
                        'class' => $class,
                        'namespace' => $namespace,
                        'debug' => $debug,
                    ]);
                    \assert(\is_string($code));
                    $cache->write($code, $builder->getResources());

                    // After the class and atomically: a crash between the two
                    // leaves a stale manifest, which rebuilds — the safe way.
                    (new Filesystem())->dumpFile($manifestFile, $manifest);

                    // ConfigCache does not do this because Symfony dumps to a
                    // content-hashed path. This path is fixed, so under
                    // opcache.validate_timestamps=0 the next require would run
                    // the bytecode of the file just replaced.
                    F::invalidateOpcodeCache($path);
                }
            } finally {
                if (false !== $lock) {
                    flock($lock, \LOCK_UN);
                    fclose($lock);
                }
            }
        }

        $fqcn = $namespace.'\\'.$class;
        if (!class_exists($fqcn, false)) {
            require $path;
        }

        /** @var Container $container */
        $container = new $fqcn();
        $container->set(self::KERNEL_ID, $kernel);

        return $container;
    }

    /**
     * Both halves of the gate: the resources ConfigCache tracks, and the
     * manifest no file can speak for. Asked again after waiting on the lock,
     * which is the whole point of having it as a method.
     */
    private function isFresh(ConfigCache $cache, string $manifestFile, string $manifest): bool
    {
        return $cache->isFresh()
            && is_file($manifestFile)
            && hash_equals($manifest, trim((string) file_get_contents($manifestFile)));
    }

    /**
     * What the dumped container was built from that no file can speak for:
     * each module's class and merged configuration, the kernel's parameter
     * bag, and this factory's own settings. Stored beside the compiled class;
     * a mismatch rebuilds it regardless of environment.
     *
     * The parameter bag belongs here for the same reason the module set does.
     * {@see build()} copies it into the builder and the compiler resolves
     * every '%name%' into the dumped class, but a kernel parameter is set in
     * PHP rather than written in a file, so there is no resource for
     * ConfigCache to stat and in prod it never re-checks anyway. Without this
     * the dumped container would serve the value it was built with forever.
     * Sorted, so the hash describes what the bag holds and not the order the
     * application happened to set it in.
     */
    public function manifestHash(Kernel $kernel): string
    {
        $modules = [];
        foreach ($kernel->modules() as $module) {
            $modules[] = [$module::class, $module->config()];
        }

        $parameters = $kernel->getParameterBag()->all();
        ksort($parameters);

        return hash('xxh128', serialize([$this->file, $this->public, $this->eventAliases, $modules, $parameters]));
    }

    /**
     * The builder before compilation: parameters, the application's and every
     * module's definitions, then the kernel bridge underneath them.
     */
    public function build(Kernel $kernel): ContainerBuilder
    {
        $builder = new ContainerBuilder(new ParameterBag([
            'kernel.base_dir' => $kernel->baseDir,
            'kernel.var_dir' => $kernel->varDir(),
            'kernel.cache_dir' => $kernel->cacheDir(),
            'kernel.environment' => $kernel->environment()->value,
            'kernel.debug' => !$kernel->environment()->isProd(),
        ]));

        foreach ($kernel->getParameterBag()->all() as $name => $value) {
            $builder->setParameter($name, $value);
        }

        $builder->addCompilerPass(new PublicServicesPass($this->public));
        $builder->addCompilerPass(new UserProviderPass());

        // #[AsEventListener] is Symfony's attribute, not a tag the container
        // understands on its own: FrameworkBundle turns it into the tag, and
        // without the bundle this is where that happens. Registered before
        // the definitions are loaded so every file below is covered.
        $this->registerEventListening($builder);

        $file = $kernel->baseDir.'/'.ltrim($this->file, '/');
        if (is_file($file)) {
            (new PhpFileLoader($builder, new FileLocator(\dirname($file))))->load(basename($file));
        }

        foreach ($kernel->modules() as $module) {
            $config = $module->config();

            // "module.<name>" is already a kernel parameter; the flat form lets
            // a definition say '%module.blog.per_page%'.
            foreach ($config as $key => $value) {
                if (\is_scalar($value) || null === $value) {
                    $builder->setParameter('module.'.$module->name().'.'.$key, $value);
                }
            }

            // The bundle half by convention: the module's own container.php,
            // then its build() hook when it implements the optional interface.
            // Neither is known to the module contract, which stays Symfony-free.
            $moduleContainerFile = $module->path().'/config/container.php';
            if (is_file($moduleContainerFile)) {
                (new PhpFileLoader($builder, new FileLocator(\dirname($moduleContainerFile))))->load('container.php');
            }

            if ($module instanceof ContainerBuilderAwareInterface) {
                $module->build($builder, $config);
            }

            // A module's programmatic definitions live in its class: track the
            // file so a debug cache notices an edit there too.
            $moduleFile = (new \ReflectionClass($module))->getFileName();
            if (false !== $moduleFile) {
                $builder->addResource(new FileResource($moduleFile));
            }
        }

        $this->registerDefaultDispatcher($builder);
        $this->bridge($builder, $kernel);

        return $builder;
    }

    /**
     * Teach the builder the attribute and the passes that read its tag.
     *
     * The attribute carries the same fields as the tag, so the conversion is
     * a copy; on a method it also fixes `method`, which is why declaring
     * both is a contradiction rather than a preference. RegisterListenersPass
     * then resolves each tag into an addListener() call on the dispatcher
     * definition, inferring the event from the listener's parameter type when
     * the attribute does not name one.
     */
    private function registerEventListening(ContainerBuilder $builder): void
    {
        $builder->registerAttributeForAutoconfiguration(
            AsEventListener::class,
            // FrameworkBundle narrows this to \ReflectionClass|\ReflectionMethod,
            // which AttributeAutoconfigurationPass reads to skip property and
            // parameter targets. Kept wide only because the annotation on
            // registerAttributeForAutoconfiguration() says \Reflector.
            static function (ChildDefinition $definition, AsEventListener $attribute, \Reflector $reflector): void {
                $tag = get_object_vars($attribute);

                if ($reflector instanceof \ReflectionMethod) {
                    if (null !== $attribute->method) {
                        throw new LogicException(\sprintf('#[AsEventListener] on method "%s::%s()" cannot also name a method.', $reflector->getDeclaringClass()->getName(), $reflector->getName()));
                    }
                    $tag['method'] = $reflector->getName();
                }

                $definition->addTag('kernel.event_listener', array_filter($tag, static fn ($value) => null !== $value));
            },
        );

        // The subscriber half of the same convenience: FrameworkBundle
        // autoconfigures the interface into the tag, and a definition using
        // autoconfigure() here expects the same.
        $builder->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber');

        // Aliases first: RegisterListenersPass reads the parameter they merge
        // into, so the pass that writes it has to have run. They are in
        // different stages, which is what guarantees the order rather than
        // leaving it to the order these two lines are written in.
        $builder->addCompilerPass(new AddEventAliasesPass($this->eventAliases));

        // BEFORE_REMOVING, where FrameworkBundle puts it: late enough that
        // child definitions, parameters and decorators are resolved, early
        // enough that a private listener has not been removed.
        $builder->addCompilerPass(new RegisterListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * The dispatcher the listeners are wired onto, unless container.php named
     * its own under either id. Aliasing the PSR-14 interface keeps the
     * bridge off it — {@see bridge()} skips an id the builder already has —
     * so autowiring a dispatcher gives the one carrying the listeners rather
     * than a call back into the kernel.
     */
    private function registerDefaultDispatcher(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition(ContainerFactoryInterface::DISPATCHER_ID) && !$builder->hasAlias(ContainerFactoryInterface::DISPATCHER_ID)) {
            $builder->register(ContainerFactoryInterface::DISPATCHER_ID, EventDispatcher::class)
                ->setPublic(true);
        }

        // All three dispatcher interfaces, as FrameworkBundle aliases them;
        // the contracts one is guarded because it is a separate package.
        $ids = [EventDispatcherInterface::class, SymfonyEventDispatcherInterface::class, EventDispatcher::class];

        if (interface_exists(ContractsEventDispatcherInterface::class)) {
            $ids[] = ContractsEventDispatcherInterface::class;
        }

        foreach ($ids as $id) {
            if (!$builder->has($id)) {
                $builder->setAlias($id, ContainerFactoryInterface::DISPATCHER_ID)->setPublic(true);
            }
        }
    }

    /**
     * Register the kernel and everything it can hand out, underneath whatever
     * the application and modules declared.
     */
    private function bridge(ContainerBuilder $builder, Kernel $kernel): void
    {
        $builder->register(self::KERNEL_ID, ContainerInterface::class)
            ->setSynthetic(true)
            ->setPublic(true);

        foreach ($kernel->declaredServiceIds() as $id) {
            if ($builder->has($id)) {
                continue;
            }

            $builder->register($id, class_exists($id) || interface_exists($id) ? $id : null)
                ->setFactory([new Reference(self::KERNEL_ID), 'get'])
                ->setArguments([$id])
                ->setShared(false)
                ->setPublic(true);
        }

        foreach ($kernel->serviceAccessors() as $type => $method) {
            if ($builder->has($type)) {
                continue;
            }

            $builder->register($type, $type)
                ->setFactory([new Reference(self::KERNEL_ID), $method])
                ->setShared(false)
                ->setPublic(true);
        }
    }
}
