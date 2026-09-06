<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection\Symfony;

use Modufolio\Appkit\Core\Kernel;
use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

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
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ContainerFactory implements ContainerFactoryInterface
{
    public const KERNEL_ID = ContainerFactoryInterface::KERNEL_ID;

    /**
     * @param string    $file   the application's Symfony definitions, relative to the base directory
     * @param bool|null $dump   write and load a compiled container class; null means "in prod only"
     * @param bool      $public make every definition public so the kernel can fetch it by id
     *                          (see {@see PublicServicesPass}); false keeps Symfony's private
     *                          default for everything but controllers
     */
    public function __construct(
        private readonly string $file = 'config/container.php',
        private readonly ?bool $dump = null,
        private readonly bool $public = true,
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
        $fresh = $cache->isFresh()
            && is_file($manifestFile)
            && hash_equals($manifest, trim((string) file_get_contents($manifestFile)));

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
            file_put_contents($manifestFile, $manifest);
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
     * What the dumped container was built from, as far as the module set is
     * concerned: each module's class and merged configuration, plus this
     * factory's own settings. Stored beside the compiled class; a mismatch
     * rebuilds it regardless of environment.
     */
    public function manifestHash(Kernel $kernel): string
    {
        $modules = [];
        foreach ($kernel->modules() as $module) {
            $modules[] = [$module::class, $module->config()];
        }

        return hash('xxh128', serialize([$this->file, $this->public, $modules]));
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

        $this->bridge($builder, $kernel);

        return $builder;
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
