<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Util;

use Composer\Autoload\ClassLoader;
use Modufolio\Appkit\Util\AutoloaderUtil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoloaderUtil::class)]
class AutoloaderUtilTest extends TestCase
{
    private function util(callable $configure): AutoloaderUtil
    {
        $loader = new ClassLoader();
        $configure($loader);

        return new AutoloaderUtil($loader);
    }

    public function testGetPathForFutureClassPsr4(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->addPsr4('App\\', '/project/src');
        });

        $this->assertSame(
            '/project/src/Entity/User.php',
            $util->getPathForFutureClass('App\\Entity\\User')
        );
    }

    public function testGetPathForFutureClassPsr0(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->add('Legacy_', '/project/lib');
        });

        $this->assertSame(
            '/project/lib/Legacy_/Thing.php',
            $util->getPathForFutureClass('Legacy_\\Thing')
        );
    }

    public function testGetPathForFutureClassPsr4Fallback(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->setPsr4('', ['/fallback/src']);
        });

        $this->assertSame(
            '/fallback/src/Any/Klass.php',
            $util->getPathForFutureClass('Any\\Klass')
        );
    }

    public function testGetPathForFutureClassPsr0Fallback(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->add('', ['/fallback/lib']);
        });

        $this->assertSame(
            '/fallback/lib/Any/Klass.php',
            $util->getPathForFutureClass('Any\\Klass')
        );
    }

    public function testGetPathForFutureClassNotFound(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
        });

        $this->assertNull($util->getPathForFutureClass('Unknown\\Klass'));
    }

    public function testGetNamespacePrefixForClass(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->addPsr4('App\\', '/project/src');
        });

        $this->assertSame('App\\', $util->getNamespacePrefixForClass('App\\Entity\\User'));
        $this->assertSame('', $util->getNamespacePrefixForClass('Other\\Entity\\User'));
    }

    public function testIsNamespaceConfiguredToAutoloadPsr4(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->addPsr4('App\\', '/project/src');
        });

        $this->assertTrue($util->isNamespaceConfiguredToAutoload('App\\Entity'));
        $this->assertTrue($util->isNamespaceConfiguredToAutoload('\\App\\Entity\\'));
        $this->assertFalse($util->isNamespaceConfiguredToAutoload('Other'));
    }

    public function testIsNamespaceConfiguredToAutoloadPsr0(): void
    {
        $util = $this->util(function (ClassLoader $loader) {
            $loader->add('Legacy\\', '/project/lib');
        });

        $this->assertTrue($util->isNamespaceConfiguredToAutoload('Legacy\\Thing'));
    }
}
