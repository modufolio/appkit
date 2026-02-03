<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Routing\Router;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Simple route loader implementation for testing purposes.
 * Does not use mocking - uses real RouteCollection instances.
 */
class TestRouteLoader implements LoaderInterface
{
    private RouteCollection $collection;

    public function __construct(?RouteCollection $collection = null)
    {
        $this->collection = $collection ?? $this->createDefaultCollection();
    }

    private function createDefaultCollection(): RouteCollection
    {
        $collection = new RouteCollection();
        $route = new Route('/test');
        $route->setDefault('_controller', 'TestController');
        $collection->add('test_route', $route);

        return $collection;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        return $this->collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return true;
    }

    public function getResolver(): \Symfony\Component\Config\Loader\LoaderResolverInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function setResolver(\Symfony\Component\Config\Loader\LoaderResolverInterface $resolver): void
    {
        // Not needed for tests
    }
}

class RouterTest extends TestCase
{
    private function createLoader(?RouteCollection $collection = null): TestRouteLoader
    {
        return new TestRouteLoader($collection);
    }

    private function createRequest(
        string $method = 'GET',
        string $path = '/test',
        string $host = 'example.com',
        string $scheme = 'https',
        ?int $port = 443,
        string $query = ''
    ): ServerRequest {
        $uri = new Uri($scheme . '://' . $host . ($port ? ':' . $port : '') . $path . ($query ? '?' . $query : ''));

        return new ServerRequest($method, $uri);
    }

    public function testConstructorWithDefaultOptions(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $this->assertInstanceOf(Router::class, $router);
    }

    public function testConstructorWithCustomOptions(): void
    {
        $loader = $this->createLoader();
        $options = [
            'cache_dir' => '/tmp/cache',
            'debug' => true,
            'resource_type' => 'yaml',
            'strict_requirements' => false,
        ];

        $router = new Router($loader, 'routes.yaml', $options);

        $this->assertInstanceOf(Router::class, $router);
    }

    public function testMatchWithValidRoute(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');
        $request = $this->createRequest('GET', '/test');

        $result = $router->match($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('_controller', $result);
        $this->assertSame('TestController', $result['_controller']);
    }

    public function testMatchPathWithValidPath(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $result = $router->matchPath('/test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('_controller', $result);
        $this->assertSame('TestController', $result['_controller']);
    }

    public function testMatchPathThrowsExceptionForInvalidPath(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $this->expectException(ResourceNotFoundException::class);
        $router->matchPath('/nonexistent');
    }

    public function testGenerateUrl(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $url = $router->generateUrl('test_route');

        $this->assertSame('/test', $url);
    }

    public function testGenerateUrlWithParameters(): void
    {
        $collection = new RouteCollection();
        $route = new Route('/user/{id}');
        $route->setDefault('_controller', 'UserController');
        $collection->add('user_show', $route);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        $url = $router->generateUrl('user_show', ['id' => 123]);

        $this->assertSame('/user/123', $url);
    }

    public function testGenerateUrlWithAbsoluteUrl(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        // Set context to generate absolute URLs
        $context = new RequestContext();
        $context->setHost('example.com');
        $context->setScheme('https');
        $router->setContext($context);

        $url = $router->generateUrl('test_route', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->assertStringContainsString('https://example.com', $url);
    }

    public function testGetUrlGenerator(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $generator = $router->getUrlGenerator();

        $this->assertInstanceOf(UrlGeneratorInterface::class, $generator);
    }

    public function testGetRouteCollection(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $collection = $router->getRouteCollection();

        $this->assertInstanceOf(RouteCollection::class, $collection);
        $this->assertTrue($collection->get('test_route') !== null);
    }

    public function testSetContext(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $context = new RequestContext();
        $context->setHost('custom.example.com');
        $context->setScheme('http');

        $router->setContext($context);

        // Generate URL to verify context was set
        $url = $router->generateUrl('test_route', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->assertStringContainsString('http://custom.example.com', $url);
    }

    public function testReset(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        // Initialize router by matching a request
        $request = $this->createRequest('GET', '/test');
        $router->match($request);

        // Reset should clear internal state
        $router->reset();

        // Router should still work after reset
        $result = $router->matchPath('/test');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('_controller', $result);
    }

    public function testMatchInitializesContextFromRequest(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $request = $this->createRequest(
            method: 'POST',
            path: '/test',
            host: 'api.example.com',
            scheme: 'https',
            port: 8443,
            query: 'foo=bar'
        );

        $router->match($request);

        // Generate absolute URL to verify context was properly initialized
        $url = $router->generateUrl('test_route', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->assertStringContainsString('api.example.com', $url);
    }

    public function testMultipleMatchCallsReuseContext(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $request1 = $this->createRequest('GET', '/test');
        $result1 = $router->match($request1);

        $request2 = $this->createRequest('GET', '/test');
        $result2 = $router->match($request2);

        $this->assertSame($result1['_controller'], $result2['_controller']);
    }

    public function testRouterWorksWithoutCacheDir(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php', ['cache_dir' => null]);

        $result = $router->matchPath('/test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('_controller', $result);
    }

    public function testDebugModeOption(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php', [
            'debug' => true,
            'cache_dir' => null
        ]);

        $this->assertInstanceOf(Router::class, $router);

        // Router should work in debug mode
        $result = $router->matchPath('/test');
        $this->assertIsArray($result);
    }

    public function testStrictRequirementsFalse(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php', [
            'strict_requirements' => false
        ]);

        $generator = $router->getUrlGenerator();

        $this->assertInstanceOf(UrlGeneratorInterface::class, $generator);
    }

    public function testMatchWithMultipleRoutes(): void
    {
        $collection = new RouteCollection();

        $homeRoute = new Route('/');
        $homeRoute->setDefault('_controller', 'HomeController');
        $collection->add('home', $homeRoute);

        $aboutRoute = new Route('/about');
        $aboutRoute->setDefault('_controller', 'AboutController');
        $collection->add('about', $aboutRoute);

        $contactRoute = new Route('/contact');
        $contactRoute->setDefault('_controller', 'ContactController');
        $collection->add('contact', $contactRoute);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        $homeResult = $router->matchPath('/');
        $aboutResult = $router->matchPath('/about');
        $contactResult = $router->matchPath('/contact');

        $this->assertSame('HomeController', $homeResult['_controller']);
        $this->assertSame('AboutController', $aboutResult['_controller']);
        $this->assertSame('ContactController', $contactResult['_controller']);
    }

    public function testMatchWithRouteRequirements(): void
    {
        $collection = new RouteCollection();

        $route = new Route('/article/{id}');
        $route->setDefault('_controller', 'ArticleController');
        $route->setRequirement('id', '\d+');
        $collection->add('article_show', $route);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // Numeric ID should match
        $result = $router->matchPath('/article/123');
        $this->assertSame('ArticleController', $result['_controller']);
        $this->assertSame('123', $result['id']);

        // Non-numeric ID should not match
        $this->expectException(ResourceNotFoundException::class);
        $router->matchPath('/article/abc');
    }

    public function testMatchWithOptionalParameter(): void
    {
        $collection = new RouteCollection();

        $route = new Route('/page/{slug}');
        $route->setDefault('_controller', 'PageController');
        $route->setDefault('slug', 'home');
        $collection->add('page', $route);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // With slug
        $result = $router->matchPath('/page/about-us');
        $this->assertSame('PageController', $result['_controller']);
        $this->assertSame('about-us', $result['slug']);

        // Without slug (default)
        $result = $router->matchPath('/page');
        $this->assertSame('home', $result['slug']);
    }

    public function testGenerateUrlWithExtraParameters(): void
    {
        $collection = new RouteCollection();
        $route = new Route('/search');
        $route->setDefault('_controller', 'SearchController');
        $collection->add('search', $route);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // Extra parameters should be added as query string
        $url = $router->generateUrl('search', ['q' => 'test', 'page' => 2]);

        $this->assertStringContainsString('/search', $url);
        $this->assertStringContainsString('q=test', $url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function testMatchWithHttpMethod(): void
    {
        $collection = new RouteCollection();

        $getRoute = new Route('/api/resource');
        $getRoute->setDefault('_controller', 'GetResourceController');
        $getRoute->setMethods(['GET']);
        $collection->add('get_resource', $getRoute);

        $postRoute = new Route('/api/resource');
        $postRoute->setDefault('_controller', 'CreateResourceController');
        $postRoute->setMethods(['POST']);
        $collection->add('create_resource', $postRoute);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // GET request
        $getRequest = $this->createRequest('GET', '/api/resource');
        $getResult = $router->match($getRequest);
        $this->assertSame('GetResourceController', $getResult['_controller']);

        // Reset to get fresh context for new request
        $router->reset();

        // POST request
        $postRequest = $this->createRequest('POST', '/api/resource');
        $postResult = $router->match($postRequest);
        $this->assertSame('CreateResourceController', $postResult['_controller']);
    }

    public function testGenerateNetworkPath(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        $context = new RequestContext();
        $context->setHost('example.com');
        $context->setScheme('https');
        $router->setContext($context);

        $url = $router->generateUrl('test_route', [], UrlGeneratorInterface::NETWORK_PATH);

        $this->assertStringStartsWith('//example.com', $url);
    }

    public function testMatchWithHost(): void
    {
        $collection = new RouteCollection();

        $mainRoute = new Route('/');
        $mainRoute->setDefault('_controller', 'MainController');
        $mainRoute->setHost('www.example.com');
        $collection->add('main', $mainRoute);

        $apiRoute = new Route('/');
        $apiRoute->setDefault('_controller', 'ApiController');
        $apiRoute->setHost('api.example.com');
        $collection->add('api', $apiRoute);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // Request to www.example.com
        $mainRequest = $this->createRequest('GET', '/', 'www.example.com');
        $mainResult = $router->match($mainRequest);
        $this->assertSame('MainController', $mainResult['_controller']);

        // Reset and request to api.example.com
        $router->reset();
        $apiRequest = $this->createRequest('GET', '/', 'api.example.com');
        $apiResult = $router->match($apiRequest);
        $this->assertSame('ApiController', $apiResult['_controller']);
    }

    public function testResetClearsContext(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        // Set initial context from first request
        $request1 = $this->createRequest('GET', '/test', 'first.example.com');
        $router->match($request1);

        // Generate URL should use first.example.com
        $url1 = $router->generateUrl('test_route', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->assertStringContainsString('first.example.com', $url1);

        // Reset the router
        $router->reset();

        // Set new context from second request
        $request2 = $this->createRequest('GET', '/test', 'second.example.com');
        $router->match($request2);

        // Generate URL should now use second.example.com
        $url2 = $router->generateUrl('test_route', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->assertStringContainsString('second.example.com', $url2);
    }

    public function testRouteCollectionIsCached(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        // Get route collection twice
        $collection1 = $router->getRouteCollection();
        $collection2 = $router->getRouteCollection();

        // Should return the same instance (cached)
        $this->assertSame($collection1, $collection2);
    }

    public function testUrlGeneratorIsCached(): void
    {
        $loader = $this->createLoader();
        $router = new Router($loader, 'routes.php');

        // Get generator twice
        $generator1 = $router->getUrlGenerator();
        $generator2 = $router->getUrlGenerator();

        // Should return the same instance (cached)
        $this->assertSame($generator1, $generator2);
    }

    public function testMatchWithScheme(): void
    {
        $collection = new RouteCollection();

        $secureRoute = new Route('/secure');
        $secureRoute->setDefault('_controller', 'SecureController');
        $secureRoute->setSchemes(['https']);
        $collection->add('secure', $secureRoute);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        // HTTPS request should match
        $httpsRequest = $this->createRequest('GET', '/secure', 'example.com', 'https');
        $result = $router->match($httpsRequest);
        $this->assertSame('SecureController', $result['_controller']);
    }

    public function testGenerateUrlPreservesSlashes(): void
    {
        $collection = new RouteCollection();

        $route = new Route('/api/v1/users/{username}');
        $route->setDefault('_controller', 'UserController');
        $collection->add('user_profile', $route);

        $loader = $this->createLoader($collection);
        $router = new Router($loader, 'routes.php');

        $url = $router->generateUrl('user_profile', ['username' => 'john-doe']);

        $this->assertSame('/api/v1/users/john-doe', $url);
    }
}
