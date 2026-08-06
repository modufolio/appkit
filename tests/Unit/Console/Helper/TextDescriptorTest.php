<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console\Helper;

use Modufolio\Appkit\Console\Helper\TextDescriptor;
use Modufolio\Appkit\Util\MakerFileLinkFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

enum SuiteFixtureEnum: string
{
    case Active = 'active';
}

class InvokableController
{
    public function __invoke(): string
    {
        return 'ok';
    }

    public function action(): string
    {
        return 'action';
    }
}

#[CoversClass(TextDescriptor::class)]
#[CoversClass(\Modufolio\Appkit\Console\Helper\Descriptor::class)]
class TextDescriptorTest extends TestCase
{
    private function describe(mixed $object, array $options = [], ?TextDescriptor $descriptor = null): string
    {
        $output = new BufferedOutput();
        ($descriptor ?? new TextDescriptor())->describe($output, $object, $options);

        return $output->fetch();
    }

    private function routeCollection(): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/', ['_controller' => 'App\Controller\HomeController::index'], [], [], '', [], ['GET']));
        $routes->add('app_store', new Route('/store', ['_controller' => [InvokableController::class, 'action']], [], [], 'example.com', ['https'], ['POST', 'PUT']));
        $routes->add('app_any', new Route('/any'));

        return $routes;
    }

    public function testDescribeRouteCollection(): void
    {
        $display = $this->describe($this->routeCollection());

        $this->assertStringContainsString('app_home', $display);
        $this->assertStringContainsString('GET', $display);
        $this->assertStringContainsString('POST|PUT', $display);
        $this->assertStringContainsString('example.com', $display);
        $this->assertStringContainsString('ANY', $display);
        $this->assertStringContainsString('/store', $display);
    }

    public function testDescribeRouteCollectionWithControllers(): void
    {
        $display = $this->describe($this->routeCollection(), ['show_controllers' => true]);

        $this->assertStringContainsString('Controller', $display);
        $this->assertStringContainsString('App\Controller\HomeController::index()', $display);
        $this->assertStringContainsString(InvokableController::class.'::action()', $display);
    }

    public function testDescribeRouteCollectionWithStyleOutput(): void
    {
        $buffer = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $buffer);

        (new TextDescriptor())->describe($buffer, $this->routeCollection(), ['output' => $io]);

        $this->assertStringContainsString('app_home', $buffer->fetch());
    }

    public function testDescribeRoute(): void
    {
        $route = new Route(
            '/items/{id}',
            ['_controller' => 'App\Controller\ItemController::show', 'flag' => true, 'mode' => SuiteFixtureEnum::Active],
            ['id' => '\d+'],
            ['utf8' => true],
            'example.com',
            ['https'],
            ['GET'],
            'context.getMethod() == "GET"'
        );

        $display = $this->describe($route, ['name' => 'app_item']);

        $this->assertStringContainsString('app_item', $display);
        $this->assertStringContainsString('/items/{id}', $display);
        $this->assertStringContainsString('example.com', $display);
        $this->assertStringContainsString('https', $display);
        $this->assertStringContainsString('id: \d+', $display);
        $this->assertStringContainsString('Condition', $display);
        $this->assertStringContainsString(Route::class, $display);
        $this->assertStringContainsString('SuiteFixtureEnum::Active', $display);
    }

    public function testDescribeRouteWithoutExtras(): void
    {
        $display = $this->describe(new Route('/simple'));

        $this->assertStringContainsString('NO CUSTOM', $display);
        $this->assertStringContainsString('ANY', $display);
        $this->assertStringNotContainsString('Condition', $display);
    }

    public function testDescribeCallableVariants(): void
    {
        $object = new InvokableController();

        $this->assertSame(InvokableController::class.'::action()', $this->describe([$object, 'action']));
        $this->assertSame('strtoupper()', $this->describe('strtoupper'));
        $this->assertSame('Closure()', $this->describe(fn () => null));
        $this->assertSame(InvokableController::class.'::__invoke()', $this->describe($object));
    }

    public function testDescribeNamedClosure(): void
    {
        $display = $this->describe(strtoupper(...));

        $this->assertStringContainsString('strtoupper', $display);
    }

    public function testDescribeClosureFromMethod(): void
    {
        $object = new InvokableController();
        $display = $this->describe($object->action(...));

        $this->assertSame(InvokableController::class.'::action()', $display);
    }

    public function testDescribeCallableWithRawText(): void
    {
        $display = $this->describe('strtoupper', ['raw_text' => true, 'raw_output' => true]);

        $this->assertSame('strtoupper()', $display);
    }

    public function testDescribeNonDescribableObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not describable');

        $this->describe(new \stdClass());
    }

    public function testDescribeRouteCollectionWithFileLinkFormatter(): void
    {
        $descriptor = new TextDescriptor(new MakerFileLinkFormatter());
        $routes = new RouteCollection();
        $routes->add('linked', new Route('/linked', ['_controller' => [InvokableController::class, 'action']]));
        $routes->add('closure', new Route('/closure', ['_controller' => fn () => null]));
        $routes->add('invokable', new Route('/invokable', ['_controller' => new InvokableController()]));
        $routes->add('string_pair', new Route('/pair', ['_controller' => InvokableController::class.'::action']));
        $routes->add('function', new Route('/function', ['_controller' => 'strtoupper']));
        $routes->add('missing', new Route('/missing', ['_controller' => 'App\Nope\MissingController::action']));

        $display = $this->describe($routes, ['show_controllers' => true], $descriptor);

        $this->assertStringContainsString('/linked', $display);
        $this->assertStringContainsString('/missing', $display);
    }
}
