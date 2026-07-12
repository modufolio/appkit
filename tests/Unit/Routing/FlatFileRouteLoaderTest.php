<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Data\Txt;
use Modufolio\Appkit\Routing\Loader\FlatFileRouteLoader;
use Modufolio\Appkit\Tests\App\FlatFile\FlatFileController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class FlatFileRouteLoaderTest extends TestCase
{
    private FlatFileRouteLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $fileLocator = new FileLocator(__DIR__.'/../../App/FlatFile');
        $this->loader = new FlatFileRouteLoader(
            locator: $fileLocator,
            controllerClass: FlatFileController::class,
            fileExtension: 'txt',
            homeFolder: 'home',
        );
    }

    public function testSupportsFlatFileType(): void
    {
        $this->assertTrue($this->loader->supports('fixtures', 'flat_file'));
    }

    public function testDoesNotSupportOtherTypes(): void
    {
        $this->assertFalse($this->loader->supports('fixtures', 'yaml'));
        $this->assertFalse($this->loader->supports('fixtures', 'xml'));
        $this->assertFalse($this->loader->supports('fixtures', null));
    }

    public function testLoadReturnsRouteCollection(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $this->assertInstanceOf(RouteCollection::class, $routes);
    }

    public function testHomeRouteIsAtRootPath(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $homeRoute = $routes->get('home');
        $this->assertNotNull($homeRoute);
        $this->assertInstanceOf(Route::class, $homeRoute);
        $this->assertSame('/', $homeRoute->getPath());
    }

    public function testAboutRouteIsLoaded(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $aboutRoute = $routes->get('about');
        $this->assertNotNull($aboutRoute);
        $this->assertSame('/about', $aboutRoute->getPath());
    }

    public function testBlogRouteIsLoaded(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $blogRoute = $routes->get('blog');
        $this->assertNotNull($blogRoute);
        $this->assertSame('/blog', $blogRoute->getPath());
    }

    public function testNestedRouteUsesUnderscoreAsNameSeparator(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $postsRoute = $routes->get('blog_posts');
        $this->assertNotNull($postsRoute);
        $this->assertSame('/blog/posts', $postsRoute->getPath());
    }

    public function testNumericPrefixIsStrippedFromSlug(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        // Directories are named 1_home, 2_about, 3_blog — prefixes must be stripped
        $this->assertNotNull($routes->get('home'));
        $this->assertNotNull($routes->get('about'));
        $this->assertNotNull($routes->get('blog'));
        $this->assertNull($routes->get('1_home'));
        $this->assertNull($routes->get('2_about'));
        $this->assertNull($routes->get('3_blog'));
    }

    public function testAllRoutesUseGetMethod(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $route) {
            $this->assertSame(['GET'], $route->getMethods());
        }
    }

    public function testAllRoutesHaveController(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $route) {
            $this->assertSame([FlatFileController::class, 'handle'], $route->getDefault('_controller'));
        }
    }

    public function testAllRoutesHaveContentFile(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $name => $route) {
            $contentFile = $route->getDefault('contentFile');
            $this->assertNotNull($contentFile, "Route '$name' is missing contentFile default");
            $this->assertFileExists($contentFile);
        }
    }

    public function testAllRoutesHaveTemplateName(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $name => $route) {
            $this->assertNotNull(
                $route->getDefault('templateName'),
                "Route '$name' is missing templateName default"
            );
        }
    }

    public function testHomeRouteDefaults(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $homeRoute = $routes->get('home');
        $this->assertSame('home', $homeRoute->getDefault('templateName'));
        $this->assertNull($homeRoute->getDefault('parent'));
        $this->assertStringEndsWith('1_home/home.txt', $homeRoute->getDefault('contentFile'));
    }

    public function testAboutRouteDefaults(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $aboutRoute = $routes->get('about');
        $this->assertSame('about', $aboutRoute->getDefault('templateName'));
        $this->assertNull($aboutRoute->getDefault('parent'));
        $this->assertStringEndsWith('2_about/about.txt', $aboutRoute->getDefault('contentFile'));
    }

    public function testNestedRouteHasParent(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $postsRoute = $routes->get('blog_posts');
        $this->assertSame('blog', $postsRoute->getDefault('parent'));
    }

    public function testTopLevelRoutesHaveNullParent(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach (['home', 'about', 'blog'] as $routeName) {
            $route = $routes->get($routeName);
            $this->assertNull($route->getDefault('parent'), "Route '$routeName' should have null parent");
        }
    }

    public function testNestedRouteContentFileAndTemplateName(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        $postsRoute = $routes->get('blog_posts');
        $this->assertSame('posts', $postsRoute->getDefault('templateName'));
        $this->assertStringEndsWith('1_posts/posts.txt', $postsRoute->getDefault('contentFile'));
    }

    public function testTotalRouteCount(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        // home, about, blog, blog_posts = 4 routes
        $this->assertCount(4, $routes);
    }

    public function testCustomFileExtension(): void
    {
        $fileLocator = new FileLocator(__DIR__.'/../../App/FlatFile');
        $loader = new FlatFileRouteLoader(
            locator: $fileLocator,
            controllerClass: FlatFileController::class,
            fileExtension: 'md',
            homeFolder: 'home',
        );

        // No .md files exist in fixtures, so no routes should be added
        $routes = $loader->load('fixtures', 'flat_file');
        $this->assertCount(0, $routes);
    }

    // -------------------------------------------------------------------------
    // Content file data assertions
    // -------------------------------------------------------------------------

    private function parseContentFile(string $contentFile): array
    {
        return Txt::decode(file_get_contents($contentFile));
    }

    private function routeData(RouteCollection $routes, string $routeName): array
    {
        $route = $routes->get($routeName);
        $this->assertNotNull($route, "Route '$routeName' not found");

        return $this->parseContentFile($route->getDefault('contentFile'));
    }

    public function testHomeContentFileHasExpectedFields(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'home');

        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('text', $data);
        $this->assertArrayHasKey('description', $data);
    }

    public function testHomeContentFileValues(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'home');

        $this->assertSame('Home', $data['title']);
        $this->assertSame('Welcome to the home page.', $data['text']);
        $this->assertSame('The main landing page of the site.', $data['description']);
    }

    public function testAboutContentFileHasExpectedFields(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'about');

        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('text', $data);
        $this->assertArrayHasKey('author', $data);
    }

    public function testAboutContentFileValues(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'about');

        $this->assertSame('About Us', $data['title']);
        $this->assertSame('Learn more about our team and mission.', $data['text']);
        $this->assertSame('Jane Doe', $data['author']);
    }

    public function testBlogContentFileValues(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'blog');

        $this->assertSame('Blog', $data['title']);
        $this->assertSame('Read our latest articles and updates.', $data['text']);
        $this->assertSame('News', $data['category']);
    }

    public function testPostsContentFileValues(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');
        $data = $this->routeData($routes, 'blog_posts');

        $this->assertSame('All Posts', $data['title']);
        $this->assertSame('Browse all blog posts.', $data['text']);
        $this->assertSame('date', $data['sort']);
    }

    public function testAllContentFilesAreReadable(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $name => $route) {
            $contentFile = $route->getDefault('contentFile');
            $data = $this->parseContentFile($contentFile);

            $this->assertNotEmpty($data, "Content of route '$name' must not be empty");
            $this->assertArrayHasKey('title', $data, "Route '$name' content must have a 'title' field");
        }
    }

    public function testContentFileKeysAreLowercased(): void
    {
        $routes = $this->loader->load('fixtures', 'flat_file');

        foreach ($routes as $name => $route) {
            $data = $this->parseContentFile($route->getDefault('contentFile'));

            foreach (array_keys($data) as $key) {
                $this->assertSame(strtolower($key), $key, "Key '$key' in route '$name' must be lowercase");
            }
        }
    }

    public function testCustomHomeFolder(): void
    {
        $fileLocator = new FileLocator(__DIR__.'/../../App/FlatFile');
        $loader = new FlatFileRouteLoader(
            locator: $fileLocator,
            controllerClass: FlatFileController::class,
            fileExtension: 'txt',
            homeFolder: 'about',
        );

        $routes = $loader->load('fixtures', 'flat_file');

        // With homeFolder='about', the 2_about directory maps to '/' with name 'home'
        $homeRoute = $routes->get('home');
        $this->assertNotNull($homeRoute);
        $this->assertSame('/', $homeRoute->getPath());
        $this->assertSame('about', $homeRoute->getDefault('templateName'));

        // No route named 'about' should exist
        $this->assertNull($routes->get('about'));
    }
}
