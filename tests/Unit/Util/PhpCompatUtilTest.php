<?php

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Console\FileManager;
use Modufolio\Appkit\Util\PhpCompatUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PhpCompatUtilTest extends TestCase
{
    public function testFallBackToPhpVersionWithoutLockFile(): void
    {
        $mockFileManager = $this->createMock(FileManager::class);
        $mockFileManager
            ->expects(self::once())
            ->method('getRootDirectory')
            ->willReturn('/test')
        ;

        $mockFileManager
            ->expects(self::once())
            ->method('fileExists')
            ->with('/test/composer.lock')
            ->willReturn(false)
        ;

        $mockFileManager
            ->expects(self::never())
            ->method('getFileContents')
        ;

        $util = new PhpCompatUtilTestFixture($mockFileManager);

        $result = $util->getVersionForTest();

        self::assertSame(\PHP_VERSION, $result);
    }

    public function testWithoutPlatformVersionSet(): void
    {
        $mockFileManager = $this->mockFileManager('{"platform-overrides": {}}');

        $util = new PhpCompatUtilTestFixture($mockFileManager);

        $result = $util->getVersionForTest();

        self::assertSame(\PHP_VERSION, $result);
    }

    private function mockFileManager(string $json): MockObject|FileManager
    {
        $mockFileManager = $this->createMock(FileManager::class);
        $mockFileManager
            ->expects(self::once())
            ->method('getRootDirectory')
            ->willReturn('/test')
        ;

        $mockFileManager
            ->expects(self::once())
            ->method('fileExists')
            ->with('/test/composer.lock')
            ->willReturn(true)
        ;

        $mockFileManager
            ->expects(self::once())
            ->method('getFileContents')
            ->with('/test/composer.lock')
            ->willReturn($json)
        ;

        return $mockFileManager;
    }
}

class PhpCompatUtilTestFixture extends PhpCompatUtil
{
    public function getVersionForTest(): string
    {
        return $this->getPhpVersion();
    }
}
