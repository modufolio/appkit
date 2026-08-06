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

    public function testExecuteWithUnknownRouteThrows(): void
    {
        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        $this->expectException(\Symfony\Component\Console\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "this-route-does-not-exist" does not exist.');

        $tester->execute(['name' => 'this-route-does-not-exist'], ['interactive' => false]);
    }

    public function testExecuteWithPartialNameMatchingMultipleRoutesNonInteractive(): void
    {
        $routes = $this->app()->router()->getRouteCollection();

        // find a substring that matches more than one route but is no route name itself
        $names = array_keys($routes->all());
        $needle = null;
        foreach (['a', 'e', 'o', 'r', 's'] as $letter) {
            $matches = array_filter($names, fn ($n) => false !== stripos($n, $letter));
            if (count($matches) > 1 && !$routes->get($letter)) {
                $needle = $letter;
                break;
            }
        }

        if (null === $needle) {
            $this->markTestSkipped('No suitable partial route name found');
        }

        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        $tester->execute(['name' => $needle], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotEmpty($tester->getDisplay());
    }

    public function testExecuteWithPartialNameMatchingSingleRoute(): void
    {
        $routes = $this->app()->router()->getRouteCollection();
        $names = array_keys($routes->all());

        // find a substring that uniquely matches one route but is no route name itself
        $needle = null;
        foreach ($names as $name) {
            $candidate = substr($name, 0, strlen($name) - 1);
            if ('' === $candidate || $routes->get($candidate)) {
                continue;
            }
            $matches = array_filter($names, fn ($n) => false !== stripos($n, $candidate));
            if (1 === count($matches)) {
                $needle = $candidate;
                break;
            }
        }

        if (null === $needle) {
            $this->markTestSkipped('No suitable unique partial route name found');
        }

        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // interactive choice offers the single match as default; accept it
        $tester->setInputs(['']);
        $tester->execute(['name' => $needle]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testCompleteSuggestsRouteNames(): void
    {
        $command = new RouterDebugCommand($this->app()->router());
        $tester = new \Symfony\Component\Console\Tester\CommandCompletionTester($command);

        $suggestions = $tester->complete(['']);

        $this->assertSame(
            array_keys($this->app()->router()->getRouteCollection()->all()),
            $suggestions
        );
    }

    public function testCommandDescription(): void
    {
        $command = new RouterDebugCommand($this->app()->router());

        $this->assertSame('debug:router', $command->getName());
        $description = $command->getDescription();
        $this->assertStringContainsString('routes', strtolower($description));
    }
}
