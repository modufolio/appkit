<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Routing\Loader\ArrayRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\RouteCollection;

/**
 * The `array` route type: one file, one entry per route, documented in
 * docs/routing.md under "Array routes".
 */
class ArrayRouteLoaderTest extends TestCase
{
    private function load(): RouteCollection
    {
        $loader = new ArrayRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        return $loader->load('array_routes.php', 'array');
    }

    public function testSupportsOnlyTheArrayType(): void
    {
        $loader = new ArrayRouteLoader(new FileLocator([__DIR__.'/fixtures']));

        $this->assertTrue($loader->supports('array_routes.php', 'array'));
        $this->assertFalse($loader->supports('array_routes.php', 'attribute'));
        $this->assertFalse($loader->supports('array_routes.php'));
    }

    public function testTheArrayKeyIsTheRouteName(): void
    {
        $routes = $this->load();

        $this->assertCount(4, $routes);
        $this->assertSame('/', $routes->get('home')?->getPath());
        $this->assertSame('/contact', $routes->get('contact')?->getPath());
    }

    public function testAnEntryWithoutAKeyIsNamedAfterItsPattern(): void
    {
        $this->assertSame('/unnamed', $this->load()->get('/unnamed')?->getPath());
    }

    public function testControllerMethodsAndRequirementsAreCarried(): void
    {
        $routes = $this->load();

        $page = $routes->get('page');
        $this->assertNotNull($page);
        $this->assertSame(['App\\Controller\\PageController', 'show'], $page->getDefault('_controller'));
        $this->assertSame(['slug' => '[a-z0-9-]+'], $page->getRequirements());
        $this->assertSame(['GET', 'POST'], $routes->get('contact')?->getMethods());
    }

    public function testMethodsDefaultToGetOnly(): void
    {
        // Unlike #[Route], which matches every method when none is given.
        $this->assertSame(['GET'], $this->load()->get('home')?->getMethods());
    }
}
