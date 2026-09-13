<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Routing\Router;
use Modufolio\Appkit\Tests\App\AppFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes read from a file, with the file recorded as a resource — the shape the
 * attribute and PHP-file loaders produce, and what the cache checks against.
 */
final class FileBackedRouteLoader implements LoaderInterface
{
    /** How often the routes were read from source — 0 means the cache answered. */
    public int $loads = 0;

    public function __construct(private readonly string $file)
    {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        ++$this->loads;
        $collection = new RouteCollection();

        /** @var array<string, string> $paths */
        $paths = require $this->file;

        foreach ($paths as $name => $path) {
            $route = new Route($path);
            $route->setDefault('_controller', 'TestController');
            $collection->add($name, $route);
        }

        $collection->addResource(new FileResource($this->file));

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return true;
    }

    public function getResolver(): LoaderResolverInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function setResolver(LoaderResolverInterface $resolver): void
    {
    }
}

#[CoversClass(Router::class)]
final class RouterCacheInvalidationTest extends TestCase
{
    private string $dir;
    private string $routeFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/appkit-router-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/cache', 0777, true);
        $this->routeFile = $this->dir.'/routes.php';
        $this->writeRoutes(['home' => '/']);

        // SelfCheckingResourceChecker memoises freshness process-wide, keyed by
        // resource and cache mtime. Symfony's own ConfigCache tests clear it the
        // same way; without it a verdict from an earlier test can be reused here.
        (new \ReflectionClass(SelfCheckingResourceChecker::class))
            ->getProperty('cache')
            ->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    /**
     * Freshness is `filemtime(routes) <= filemtime(cache)`, at one-second
     * resolution, so the mtime is set explicitly rather than left to the clock:
     * `$age` of -10 is a file the cache is allowed to consider current, +5 an
     * edit that must invalidate it.
     *
     * @param array<string, string> $paths
     */
    private function writeRoutes(array $paths, int $age = -10): void
    {
        file_put_contents($this->routeFile, '<?php return '.var_export($paths, true).";\n");
        touch($this->routeFile, time() + $age);
        clearstatcache(true, $this->routeFile);
    }

    /** A router as the kernel builds it: a fresh instance, one shared cache directory. */
    private function router(bool $debug, ?FileBackedRouteLoader $loader = null): Router
    {
        return new Router(
            $loader ?? new FileBackedRouteLoader($this->routeFile),
            'routes.php',
            ['cache_dir' => $this->dir.'/cache', 'debug' => $debug]
        );
    }

    public function testAddingARouteInDevIsPickedUpWithoutClearingTheCache(): void
    {
        $this->assertSame('home', $this->router(debug: true)->matchPath('/')['_route']);
        $this->assertFileExists($this->dir.'/cache/url_matching_routes.php');

        $this->writeRoutes(['home' => '/', 'about' => '/about'], age: 5);

        // A new instance with its own loader, as the next request would build.
        $loader = new FileBackedRouteLoader($this->routeFile);

        $this->assertSame(
            'about',
            $this->router(debug: true, loader: $loader)->matchPath('/about')['_route'],
            'A route added in dev should be reachable on the next request.'
        );
        $this->assertSame(1, $loader->loads, 'The routes should have been reloaded from source.');
    }

    /**
     * The other side of invalidation, and the reason the cache exists at all:
     * when nothing changed the routes are never read from source again.
     */
    public function testAnUnchangedDevCacheIsReusedWithoutReloadingTheRoutes(): void
    {
        $this->router(debug: true)->matchPath('/');

        $loader = new FileBackedRouteLoader($this->routeFile);

        $this->assertSame('home', $this->router(debug: true, loader: $loader)->matchPath('/')['_route']);
        $this->assertSame(0, $loader->loads, 'A fresh cache should answer without touching the loader.');
    }

    public function testTheGeneratorIsInvalidatedInDevToo(): void
    {
        $this->assertSame('/', $this->router(debug: true)->generateUrl('home'));

        $this->writeRoutes(['home' => '/', 'about' => '/about'], age: 5);

        $this->assertSame('/about', $this->router(debug: true)->generateUrl('about'));
    }

    public function testARouteRemovedInDevStopsMatching(): void
    {
        $this->writeRoutes(['home' => '/', 'about' => '/about']);
        $this->assertSame('about', $this->router(debug: true)->matchPath('/about')['_route']);

        $this->writeRoutes(['home' => '/'], age: 5);

        $this->expectException(ResourceNotFoundException::class);
        $this->router(debug: true)->matchPath('/about');
    }

    /**
     * The other half of the contract: production never re-checks the cache, so
     * a route added after it was warmed stays invisible until it is cleared.
     * This is what makes `rm -rf var/cache/prod/router` a deployment step.
     */
    public function testAddingARouteInProdIsNotPickedUpUntilTheCacheIsCleared(): void
    {
        $this->assertSame('home', $this->router(debug: false)->matchPath('/')['_route']);

        $this->writeRoutes(['home' => '/', 'about' => '/about'], age: 5);

        try {
            $this->router(debug: false)->matchPath('/about');
            $this->fail('Production should still be serving the cached routes.');
        } catch (ResourceNotFoundException) {
            // expected
        }

        unlink($this->dir.'/cache/url_matching_routes.php');

        $this->assertSame(
            'about',
            $this->router(debug: false)->matchPath('/about')['_route'],
            'Clearing the cache should let production see the new route.'
        );
    }

    /**
     * Guards the kernel wiring rather than the router: a null cache_dir would
     * leave the router recompiling every route on every request, silently and
     * only outside production.
     */
    public function testTheKernelGivesTheRouterACacheDirectory(): void
    {
        $varDir = sys_get_temp_dir().'/appkit-kernel-router-'.bin2hex(random_bytes(4));

        try {
            $app = AppFactory::create(dirname(__DIR__, 3), null, null, $varDir);
            $app->router()->matchPath('/admin/dashboard');

            $this->assertFileExists(
                $app->cacheDir().'/router/url_matching_routes.php',
                'The kernel should hand the router a cache directory outside production too.'
            );
        } finally {
            if (is_dir($varDir)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($varDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($varDir);
            }
        }
    }

    public function testCachedRouteDataIsRebuiltInDevAndFrozenInProd(): void
    {
        $names = static fn (RouteCollection $routes): array => array_keys($routes->all());

        $this->assertSame(['home'], $this->router(debug: true)->cachedRouteData('names', $names));

        $this->writeRoutes(['home' => '/', 'about' => '/about'], age: 5);

        $this->assertSame(
            ['home', 'about'],
            $this->router(debug: true)->cachedRouteData('names', $names),
            'A projection should track the routes it was derived from.'
        );
    }
}
