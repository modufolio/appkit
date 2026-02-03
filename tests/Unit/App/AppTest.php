<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Appkit\Core\Environment;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AppTest extends AppTestCase
{
    public function testAppInstanceCreation(): void
    {
        $this->assertInstanceOf(App::class, $this->app());
        $this->assertInstanceOf(ContainerInterface::class, $this->app());
        $this->assertInstanceOf(RequestHandlerInterface::class, $this->app());
    }

    public function testAppVersion(): void
    {
        $this->assertSame('0.0.7', App::VERSION);
    }

    public function testBaseDirectoryAccess(): void
    {
        $expectedBaseDir = dirname(__DIR__, 3);
        $this->assertSame($expectedBaseDir, $this->app()->baseDir);
    }

    public function testEnvironmentIsTest(): void
    {
        $environment = $this->app()->environment();
        $this->assertInstanceOf(Environment::class, $environment);
        $this->assertTrue($environment->isTest(), 'Environment should be set to test');
    }

    public function testBaseUrlGeneration(): void
    {
        $baseUrl = $this->app()->baseUrl();
        $this->assertIsString($baseUrl);
        $this->assertStringStartsWith('http', $baseUrl);
    }

    public function testUrlGeneration(): void
    {
        $baseUrl = $this->app()->baseUrl();
        $this->assertSame($baseUrl, $this->app()->url());
        $this->assertSame($baseUrl . '/test', $this->app()->url('/test'));
        $this->assertSame($baseUrl . '/test', $this->app()->url('test'));
    }

    public function testParameterBagOperations(): void
    {
        $this->app()->setParameter('test_key', 'test_value');

        $this->assertTrue($this->app()->hasParameter('test_key'));
        $this->assertSame('test_value', $this->app()->getParameter('test_key'));
        $this->assertFalse($this->app()->hasParameter('non_existent'));
    }
}
