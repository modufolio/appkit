<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\ControllersDebugCommand;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ControllersDebugCommandTest extends AppTestCase
{
    public function testAllConfiguredControllersWireSuccessfully(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('OK', $output);
        $this->assertStringContainsString('wired successfully', $output);
    }

    public function testListsEveryDistinctControllerReferencedByARoute(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $expectedIds = [];
        foreach ($this->app()->router()->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');
            if (is_array($controller) && isset($controller[0]) && is_string($controller[0])) {
                $expectedIds[$controller[0]] = true;
            }
        }

        $this->assertNotEmpty($expectedIds, 'Test app must configure at least one route to exercise this command');

        $output = $tester->getDisplay();
        foreach (array_keys($expectedIds) as $id) {
            $this->assertStringContainsString($id, $output);
        }
    }

    public function testFailsWhenAControllerCannotBeWired(): void
    {
        $tester = new CommandTester(
            new ControllersDebugCommand($this->app(), new class implements \Modufolio\Appkit\Routing\RouterInterface {
                public function match(\Psr\Http\Message\ServerRequestInterface $request): array
                {
                    return [];
                }

                public function matchPath(string $pathinfo): array
                {
                    return [];
                }

                public function generateUrl(
                    string $name,
                    array $parameters = [],
                    int $referenceType = \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_PATH,
                ): string {
                    return '';
                }

                public function getUrlGenerator(): \Symfony\Component\Routing\Generator\UrlGeneratorInterface
                {
                    throw new \LogicException('Not needed for this test');
                }

                public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection
                {
                    $routes = new \Symfony\Component\Routing\RouteCollection();
                    $routes->add('broken', new \Symfony\Component\Routing\Route('/broken', [
                        '_controller' => [ControllerThatCannotBeConstructed::class, 'index'],
                    ]));

                    return $routes;
                }
            })
        );

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString(ControllerThatCannotBeConstructed::class, $output);
    }

    public function testCommandDescription(): void
    {
        $command = new ControllersDebugCommand($this->app(), $this->app()->router());

        $this->assertSame('debug:controllers', $command->getName());
        $description = $command->getDescription();
        $this->assertStringContainsString('controller', strtolower($description));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ControllersDebugCommand($this->app(), $this->app()->router()));
    }
}

final class ControllerThatCannotBeConstructed
{
    private UnresolvableDependency $dependency;

    public function __construct(UnresolvableDependency $dependency)
    {
        $this->dependency = $dependency;
    }

    public function getDependency(): UnresolvableDependency
    {
        return $this->dependency;
    }
}

final class UnresolvableDependency
{
    private function __construct()
    {
    }
}
