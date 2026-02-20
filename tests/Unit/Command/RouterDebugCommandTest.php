<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\RouterDebugCommand;
use Modufolio\Appkit\Tests\Case\AppTestCase;

class RouterDebugCommandTest extends AppTestCase
{
    public function testExecuteDebugRouterWithoutArguments(): void
    {
        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        // Should display routes from the application
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testExecuteDebugRouterWithSpecificRoute(): void
    {
        // Get an actual route from the app
        $routes = $this->app()->router()->getRouteCollection();
        $routeNames = array_keys($routes->all());

        if (empty($routeNames)) {
            $this->markTestSkipped('No routes configured in application');
        }

        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Test with the first route
        $routeName = $routeNames[0];
        $tester->execute([$routeName]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString($routeName, $output);
    }

    public function testCommandDescription(): void
    {
        $command = new RouterDebugCommand($this->app()->router());

        $this->assertNotNull($command->getDefinition());
        $this->assertSame('debug:router', $command->getName());
        $description = $command->getDescription();
        $this->assertStringContainsString('routes', strtolower($description));
    }
}
