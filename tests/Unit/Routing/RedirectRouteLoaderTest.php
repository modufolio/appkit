<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Routing\Loader\RedirectController;
use Modufolio\Appkit\Routing\Loader\RedirectRouteLoader;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;

class RedirectRouteLoaderTest extends TestCase
{
    private function load(): \Symfony\Component\Routing\RouteCollection
    {
        $loader = new RedirectRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        return $loader->load('redirects.php', 'redirect');
    }

    public function testSupportsOnlyTheRedirectType(): void
    {
        $loader = new RedirectRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        $this->assertTrue($loader->supports('redirects.php', 'redirect'));
        $this->assertFalse($loader->supports('redirects.php', 'attribute'));
        $this->assertFalse($loader->supports('redirects.php'));
    }

    public function testRoutesCarryTargetStatusAndNormalisedSource(): void
    {
        $routes = $this->load();

        $this->assertCount(2, $routes);

        $byPath = [];
        foreach ($routes as $route) {
            $byPath[$route->getPath()] = $route;
        }

        $this->assertSame('/', $byPath['/home']->getDefault('target'));
        $this->assertSame(301, $byPath['/home']->getDefault('statusCode'));

        // A source without a leading slash is normalised to one.
        $this->assertArrayHasKey('/old-blog', $byPath);
        $this->assertSame('/blog', $byPath['/old-blog']->getDefault('target'));
        $this->assertSame(302, $byPath['/old-blog']->getDefault('statusCode'));

        $this->assertSame(
            [RedirectController::class, 'redirect'],
            $byPath['/home']->getDefault('_controller'),
        );
    }

    public function testRouteNamesAreStableAcrossLoads(): void
    {
        // Names hash source|target, so reloading (or reordering unrelated
        // entries) never renames a route another config might reference.
        $first = array_keys(iterator_to_array($this->load()));
        $second = array_keys(iterator_to_array($this->load()));

        $this->assertSame($first, $second);
    }

    public function testAStatusWithoutRedirectSemanticsIsRefusedAtConfigureTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/status 200, which has no redirect semantics.*301, 302, 303, 307, 308/');

        (new \Modufolio\Appkit\Routing\RedirectConfigurator())->redirect('/a', '/b', 200);
    }

    public function testRedirectLoopsAreRefusedWithTheFullChain(): void
    {
        $loader = new RedirectRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        try {
            $loader->load('redirects-loop.php', 'redirect');
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('/a -> /b -> /c -> /a', $e->getMessage());
            $this->assertStringContainsString('/self -> /self', $e->getMessage());
        }
    }

    public function testChainsWithoutCyclesLoadAndRouteTargetsCarryTheName(): void
    {
        $loader = new RedirectRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        // /a -> /b -> /c is a chain, not a loop — legal.
        $routes = $loader->load('redirects-routes.php', 'redirect');
        $this->assertCount(3, $routes);

        $byPath = [];
        foreach ($routes as $route) {
            $byPath[$route->getPath()] = $route;
        }

        $this->assertSame('blog_index', $byPath['/old-blog']->getDefault('routeName'));
        $this->assertSame(302, $byPath['/old-blog']->getDefault('statusCode'));
        $this->assertNull($byPath['/old-blog']->getDefault('target'));
    }

    public function testControllerGeneratesTheTargetFromARouteName(): void
    {
        $collection = new \Symfony\Component\Routing\RouteCollection();
        $collection->add('blog_index', new \Symfony\Component\Routing\Route('/blog'));
        $generator = new \Symfony\Component\Routing\Generator\UrlGenerator(
            $collection,
            new \Symfony\Component\Routing\RequestContext(),
        );

        $response = (new RedirectController())->redirect(
            new ServerRequest('GET', 'http://localhost/old-blog'),
            $generator,
            statusCode: 302,
            routeName: 'blog_index',
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/blog', $response->getHeaderLine('Location'));
    }

    public function testControllerThrowsLoudlyOnAnUnknownRouteName(): void
    {
        $generator = new \Symfony\Component\Routing\Generator\UrlGenerator(
            new \Symfony\Component\Routing\RouteCollection(),
            new \Symfony\Component\Routing\RequestContext(),
        );

        $this->expectException(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);

        (new RedirectController())->redirect(
            new ServerRequest('GET', 'http://localhost/old'),
            $generator,
            routeName: 'nope',
        );
    }

    public function testControllerRedirectsWithLocationAndEscapedBody(): void
    {
        $request = new ServerRequest('GET', 'http://localhost/home');

        $response = (new RedirectController())->redirect(
            $request,
            new \Symfony\Component\Routing\Generator\UrlGenerator(
                new \Symfony\Component\Routing\RouteCollection(),
                new \Symfony\Component\Routing\RequestContext(),
            ),
            statusCode: 301,
            target: '/"><script>x</script>',
        );

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/"><script>x</script>', $response->getHeaderLine('Location'));
        // The interstitial body escapes the target — a crafted redirect target
        // must not become markup.
        $this->assertStringNotContainsString('<script>', (string) $response->getBody());
    }
}
