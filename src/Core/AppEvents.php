<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Data\PHP;
use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use Modufolio\Appkit\Event\EventConfigurator;
use Modufolio\Appkit\Event\Loader\ArrayListenerLoader;
use Modufolio\Appkit\Event\Loader\AttributeListenerLoader;
use Modufolio\Appkit\Toolkit\F;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;

/**
 * Listeners for the kernel's own dispatcher: `configureEvents()` takes the
 * declaration, `listenerMap()` resolves and caches it, `getListener()` builds
 * one — the event counterparts of the controller trio.
 *
 * The dispatcher is the worker's; a listener is the request's, like a
 * controller, so it may inject request-scoped services.
 *
 * Behavior only: every property this trait touches is declared on
 * {@see Kernel}. See [Events](../../docs/events.md#listeners-the-kernel-wires).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppEvents
{
    /**
     * Compiled maps by path, cleared in the same breath as the router's —
     * see {@see getCompiledListeners()} for why it can also be null.
     *
     * @var array<string, array<string, array{event: string, listener: array{0: class-string, 1: string}, priority: int}>>|null
     */
    private static ?array $compiledListeners = [];

    private ?ConfigCacheFactoryInterface $listenerCacheFactory = null;

    /**
     * Declare listeners, before boot() and beside the other configurators.
     */
    public function configureEvents(EventConfigurator $configurator): static
    {
        $this->eventConfigurator = $configurator;

        return $this;
    }

    /**
     * The resolved wiring, in the shape `config/events.php` is written in,
     * highest priority first; equal priorities keep declaration order.
     *
     * @return array<string, array{event: string, listener: array{0: class-string, 1: string}, priority: int}>
     */
    public function listenerMap(): array
    {
        if (null !== $this->listenerMap) {
            return $this->listenerMap;
        }

        if (null === $this->eventConfigurator && !isset($this->fileMap['events'])) {
            return $this->listenerMap = [];
        }

        // As the router caches: the writer runs only when it has to, so a
        // fresh cache never pays for the scan that produced it.
        $cache = $this->getListenerCacheFactory()->cache(
            $this->cacheDir().'/events/listeners.php',
            function (ConfigCacheInterface $cache): void {
                $configurator = $this->eventConfiguration();

                $cache->write(
                    $this->compileListenerMap($this->loadListeners($configurator)),
                    $this->listenerResources($configurator),
                );
                // ConfigCache::write() does not invalidate the opcode cache
                // the way Data\PHP::write() does, and this file is required.
                F::invalidateOpcodeCache($cache->getPath());
                unset(self::$compiledListeners[$cache->getPath()]);
            },
        );

        return $this->listenerMap = self::getCompiledListeners($cache->getPath());
    }

    /**
     * Everything the configurator imports, resolved through the loaders.
     *
     * @return list<array{event: string, listener: array{0: class-string, 1: string}, priority: int}>
     */
    protected function loadListeners(EventConfigurator $configurator): array
    {
        $loader = $this->listenerLoader();
        $listeners = [];

        foreach ($configurator->getImports() as $import) {
            $loaded = $loader->load($import['resource'], $import['type']);

            if (!\is_array($loaded)) {
                throw new \LogicException(sprintf('The loader for type "%s" must return a list of listeners, %s given.', $import['type'] ?? 'null', get_debug_type($loaded)));
            }

            foreach ($loaded as $listener) {
                $listeners[] = $listener;
            }
        }

        // Importing the same class twice — through a directory and by name,
        // or from two files — is a duplicate declaration, not a request to
        // run it twice.
        $unique = [];
        foreach ($listeners as $listener) {
            $unique[implode('|', [$listener['event'], $listener['listener'][0], $listener['listener'][1], $listener['priority']])] = $listener;
        }

        return array_values($unique);
    }

    /**
     * The loaders `import()` can name a type from — supplied by the
     * application the way `$routeLoader` is, or the framework's two.
     */
    protected function listenerLoader(): LoaderInterface
    {
        if (null !== $this->listenerLoader) {
            return $this->listenerLoader;
        }

        $locator = new FileLocator([$this->baseDir]);
        $classes = new AttributeListenerLoader($locator);

        return $this->listenerLoader = new DelegatingLoader(new LoaderResolver([
            $classes,
            new ArrayListenerLoader($locator, $classes),
        ]));
    }

    /**
     * Wire the map onto a dispatcher. Each listener is a closure, so it is
     * built the first time its event fires and never if it does not.
     * Callables from `on()` are added here too, neither cached nor lazy.
     */
    public function registerListeners(EventDispatcherInterface $dispatcher): void
    {
        if (!$dispatcher instanceof SymfonyEventDispatcherInterface) {
            return;
        }

        foreach ($this->listenerMap() as $definition) {
            [$class, $method] = $definition['listener'];

            // All three arguments the dispatcher supplies, not just the
            // event: Symfony calls every listener with ($event, $eventName,
            // $dispatcher), and a listener that asks for the second or third
            // would otherwise work in the container step and raise an
            // ArgumentCountError here. PHP ignores the extra arguments for a
            // listener that declares only the event, which is most of them.
            $dispatcher->addListener(
                $definition['event'],
                fn (object $e, string $eventName, EventDispatcherInterface $bus) => $this->getListener($class)->{$method}($e, $eventName, $bus),
                $definition['priority'],
            );
        }

        foreach ($this->eventConfiguration()->getCallables() as $callable) {
            $dispatcher->addListener($callable['event'], $callable['listener'], $callable['priority']);
        }
    }

    /**
     * Build a listener, once per request.
     *
     * The chain is {@see AppControllers::getController()}'s, request-instance
     * cache included: a listener the fallback container declares is
     * built there, one the kernel declares is fetched from the kernel, and
     * anything else has its constructor resolved by reflection. Unlike a
     * controller, an unwired listener is not warned about — a listener with
     * no constructor is the common case, not an oversight.
     *
     * @param class-string $id
     */
    public function getListener(string $id): object
    {
        // A listener can fire where a controller never does — from a console
        // command, or from an event dispatched during boot — and there is no
        // request to scope an instance to there. Then it is simply built each
        // time, which is the safe end of the trade.
        $scoped = null !== $this->state;

        if ($scoped && $this->state()->hasRequestInstance($id)) {
            return $this->state()->getRequestInstance($id);
        }

        if (!class_exists($id)) {
            throw new \InvalidArgumentException(sprintf('Listener class "%s" does not exist.', $id));
        }

        if ($this->fallbackHas($id)) {
            $listener = $this->fallbackContainer->get($id);

            if (!\is_object($listener)) {
                throw new \LogicException(sprintf('The fallback container returned %s for listener "%s".', get_debug_type($listener), $id));
            }
        } elseif (null !== $hint = $this->fallbackRemovalHint($id)) {
            // Declared privately in the Symfony container: say so, rather than
            // quietly rebuilding it by reflection behind its definition's back.
            throw new \LogicException($hint);
        } elseif (isset($this->services[$id])) {
            $listener = $this->get($id, $id);

            if (!\is_object($listener)) {
                throw new \LogicException(sprintf('Service "%s" is declared but is not an object, so it cannot listen.', $id));
            }
        } else {
            $arguments = (new ReflectionControllerArgumentResolver($this))->resolveArguments($id);
            $listener = [] === $arguments
                ? new $id()
                : new $id(...array_values($this->resolveDependencies($arguments)));
        }

        if ($scoped) {
            $this->state()->setRequestInstance($id, $listener);
        }

        return $listener;
    }

    /**
     * The configurator, from `configureEvents()` or the mapped
     * `config/events.php`; neither gives an empty declaration.
     */
    protected function eventConfiguration(): EventConfigurator
    {
        if ($this->eventListenersResolved) {
            /** @var EventConfigurator $resolved */
            $resolved = $this->eventConfigurator;

            return $resolved;
        }

        // Resolved lazily rather than in configureEvents(), so declaring
        // listeners before configureModules() is not a silently different
        // thing from declaring them after.
        $configurator = $this->eventConfigurator ?? new EventConfigurator();

        if (null === $this->eventConfigurator && isset($this->fileMap['events'])) {
            $closure = require $this->fileMap['events'];
            $closure($configurator);
        }

        $this->eventListenersResolved = true;

        return $this->eventConfigurator = $this->withModuleListeners($configurator);
    }

    /**
     * The map as a PHP file, in the shape `config/events.php` is written in.
     *
     * @param list<array{event: string, listener: array{0: class-string, 1: string}, priority: int}> $listeners
     */
    private function compileListenerMap(array $listeners): string
    {
        // Highest priority first, so the file reads in the order it fires.
        // PHP's sort is stable, so equal priorities keep declaration order.
        usort($listeners, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $map = [];
        foreach ($listeners as $listener) {
            [$class, $method] = $listener['listener'];

            // The name a route would get from AttributeClassLoader when it
            // declares none: the class and method, lowercased.
            $name = strtolower(str_replace('\\', '_', $class).'_'.$method);

            $map[$name] = [
                'event' => $listener['event'],
                'listener' => [$class, $method],
                'priority' => $listener['priority'],
            ];
        }

        return "<?php\n\n// Generated from the event configuration — do not edit.\n// Same shape as a config/events.php, highest priority first.\n\nreturn "
            .PHP::encode($map).";\n";
    }

    /**
     * Fold in `listeners()` and `listenerPaths()` from each module. The
     * application is folded in first, so it wins the deduplication.
     */
    protected function withModuleListeners(EventConfigurator $configurator): EventConfigurator
    {
        foreach ($this->modules as $module) {
            if ([] !== $listeners = $module->listeners()) {
                $configurator->import($listeners, 'array');
            }

            foreach ($module->listenerPaths() as $path) {
                $configurator->import($path, 'attribute');
            }
        }

        return $configurator;
    }

    private function getListenerCacheFactory(): ConfigCacheFactoryInterface
    {
        return $this->listenerCacheFactory ??= new ConfigCacheFactory(!$this->environment()->isProd());
    }

    /**
     * As {@see \Modufolio\Appkit\Routing\Router} reads its compiled routes:
     * with opcache on, required rather than held in a static, which would
     * serve a rebuilt map's predecessor for the life of the process.
     *
     * @return array<string, array{event: string, listener: array{0: class-string, 1: string}, priority: int}>
     */
    private static function getCompiledListeners(string $path): array
    {
        if ([] === self::$compiledListeners && \function_exists('opcache_invalidate')
            && filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)
            && (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)
                || filter_var(\ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL))) {
            self::$compiledListeners = null;
        }

        if (null === self::$compiledListeners) {
            return require $path;
        }

        return self::$compiledListeners[$path] ??= require $path;
    }

    /**
     * What the cache is checked against outside prod, so a listener added to
     * a scanned directory shows up without clearing anything.
     *
     * @return list<DirectoryResource|FileResource>
     */
    private function listenerResources(EventConfigurator $configurator): array
    {
        $resources = [];

        if (isset($this->fileMap['events']) && is_file($this->fileMap['events'])) {
            $resources[] = new FileResource($this->fileMap['events']);
        }

        // Whatever the imports resolve to on disk. A class name resolves to
        // nothing here — the file it lives in is covered by the directory it
        // was imported from, and a class imported by name is covered by
        // config/events.php itself changing.
        foreach ($configurator->getImports() as $import) {
            if (!\is_string($import['resource']) || class_exists($import['resource'])) {
                continue;
            }

            $path = str_starts_with($import['resource'], '/')
                ? $import['resource']
                : $this->baseDir.'/'.ltrim($import['resource'], './');

            if (is_dir($path)) {
                $resources[] = new DirectoryResource($path, '/\.php$/');
            } elseif (is_file($path)) {
                $resources[] = new FileResource($path);
            }
        }

        // A module hands over the array, not the file it read it from, so the
        // file is tracked here — same convention AbstractModule::listeners()
        // uses. A module that builds its list some other way has no file to
        // watch, and nothing to go stale against.
        foreach ($this->modules as $module) {
            $file = $module->path().'/config/events.php';

            if (is_file($file)) {
                $resources[] = new FileResource($file);
            }
        }

        return $resources;
    }
}
