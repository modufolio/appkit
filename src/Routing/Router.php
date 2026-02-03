<?php

namespace Modufolio\Appkit\Routing;

use Modufolio\Appkit\Core\ResetInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Router implementation for matching requests and generating URLs
 *
 * Memory Leak Prevention:
 * - Static route cache is cleared in reset() to prevent unbounded growth
 * - Request context is nullified to release query strings and path data
 * - Matcher and generator instances are cleared to free compiled route data
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Router implements RouterInterface, ResetInterface
{
    private ?UrlMatcherInterface $matcher = null;
    private ?UrlGeneratorInterface $generator = null;
    private ?RequestContext $context = null;
    private ?RouteCollection $collection = null;
    private ?ConfigCacheFactoryInterface $configCacheFactory = null;

    /**
     * Static cache for compiled routes. Cleared in reset() to prevent memory leaks
     * in long-running workers.
     *
     * @var array<string, array>|null
     */
    private static ?array $routeCache = [];

    public function __construct(
        private readonly LoaderInterface $routeLoader,
        private readonly mixed $routeResource,
        private array $options = []
    ) {
        $this->setDefaultOptions();
    }

    private function setDefaultOptions(): void
    {
        $this->options = array_merge([
            'cache_dir' => null,
            'debug' => false,
            'resource_type' => null,
            'strict_requirements' => true,
        ], $this->options);
    }

    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function match(ServerRequestInterface $request): array
    {
        $this->ensureContext($request);
        return $this->matchPath($request->getUri()->getPath());
    }

    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function matchPath(string $pathinfo): array
    {
        return $this->getMatcher()->match($pathinfo);
    }

    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function generateUrl(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH
    ): string {
        $context = $this->getContext();
        return $this->getUrlGenerator()->generate($name, $parameters, $referenceType);
    }

    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function getUrlGenerator(): UrlGeneratorInterface
    {
        if (isset($this->generator)) {
            return $this->generator;
        }


        if (null === $this->options['cache_dir']) {
            $routes = $this->getRouteCollection();
            $generatorDumper = new CompiledUrlGeneratorDumper($routes);
            $routes = array_merge(
                $generatorDumper->getCompiledRoutes(),
                $generatorDumper->getCompiledAliases()
            );

            $this->generator = new CompiledUrlGenerator($routes, $this->getContext());
        } else {
            $cache = $this->getConfigCacheFactory()->cache(
                $this->options['cache_dir'] . '/url_generating_routes.php',
                function (ConfigCacheInterface $cache) {
                    $dumper = new CompiledUrlGeneratorDumper($this->getRouteCollection());
                    $cache->write($dumper->dump(), $this->getRouteCollection()->getResources());
                    unset(self::$routeCache[$cache->getPath()]);
                }
            );

            $this->generator = new CompiledUrlGenerator(
                self::getCompiledRoutes($cache->getPath()),
                $this->getContext()
            );
        }

        $this->generator->setStrictRequirements($this->options['strict_requirements']);

        return $this->generator;
    }

    /**
     * Get the URL matcher instance
     *
     * @return UrlMatcherInterface|RequestMatcherInterface
     * @throws \Exception
     */
    private function getMatcher(): UrlMatcherInterface|RequestMatcherInterface
    {
        if (isset($this->matcher)) {
            return $this->matcher;
        }

        if (null === $this->options['cache_dir']) {
            $routes = $this->getRouteCollection();
            $routes = (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes();
            $this->matcher = new CompiledUrlMatcher($routes, $this->getContext());

            return $this->matcher;
        }

        $cache = $this->getConfigCacheFactory()->cache(
            $this->options['cache_dir'] . '/url_matching_routes.php',
            function (ConfigCacheInterface $cache) {
                $dumper = new CompiledUrlMatcherDumper($this->getRouteCollection());
                $cache->write($dumper->dump(), $this->getRouteCollection()->getResources());
                unset(self::$routeCache[$cache->getPath()]);
            }
        );

        return $this->matcher = new CompiledUrlMatcher(
            self::getCompiledRoutes($cache->getPath()),
            $this->getContext()
        );
    }

    /**
     * Get the route collection
     * @throws \Exception
     */
    public function getRouteCollection(): RouteCollection
    {
        return $this->collection ??= $this->routeLoader->load(
            $this->routeResource,
            $this->options['resource_type']
        );
    }

    /**
     * Get or create the request context
     */
    private function getContext(): RequestContext
    {
        return $this->context ??= new RequestContext();
    }

    /**
     * Ensure context is initialized from request
     */
    private function ensureContext(ServerRequestInterface $request): void
    {
        if ($this->context !== null) {
            return;
        }

        $uri = $request->getUri();
        $context = new RequestContext();
        $context
            ->setMethod($request->getMethod())
            ->setHost($uri->getHost())
            ->setScheme($uri->getScheme())
            ->setHttpPort($uri->getPort() ?: 80)
            ->setHttpsPort($uri->getPort() ?: 443)
            ->setPathInfo($uri->getPath())
            ->setQueryString($uri->getQuery());

        $this->setContext($context);
    }

    /**
     * Set the request context
     */
    public function setContext(RequestContext $context): void
    {
        $this->context = $context;

        if (isset($this->matcher)) {
            $this->matcher->setContext($context);
        }
        if (isset($this->generator)) {
            $this->generator->setContext($context);
        }
    }

    /**
     * Get the config cache factory
     */
    private function getConfigCacheFactory(): ConfigCacheFactoryInterface
    {
        return $this->configCacheFactory ??= new ConfigCacheFactory($this->options['debug']);
    }

    /**
     * Get compiled routes from cache file
     */
    private static function getCompiledRoutes(string $path): array
    {
        if ([] === self::$routeCache && \function_exists('opcache_invalidate')
            && filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)
            && (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)
                || filter_var(\ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL))) {
            self::$routeCache = null;
        }

        if (null === self::$routeCache) {
            return require $path;
        }

        return self::$routeCache[$path] ??= require $path;
    }

    /**
     * Reset the router state to prevent memory leaks in long-running workers.
     *
     * Clears:
     * - Request context (contains path and query strings)
     * - Matcher and generator instances
     * - Route collection
     * - Static route cache (prevents unbounded growth)
     */
    public function reset(): void
    {
        $this->context = null;
        $this->matcher = null;
        $this->generator = null;
        $this->collection = null;

        // Clear static route cache to prevent memory leaks in long-running workers
        // This is critical for RoadRunner/FrankenPHP where the same process handles
        // multiple requests and static variables persist across requests
        self::$routeCache = [];
    }
}
