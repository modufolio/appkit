<?php

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Util\MakerFileLinkFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MakerFileLinkFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean environment before each test
        putenv('MAKER_DISABLE_FILE_LINKS');
    }

    protected function tearDown(): void
    {
        // Clean environment after each test
        putenv('MAKER_DISABLE_FILE_LINKS');
    }

    public static function provideMakeLinkedPath(): \Generator
    {
        yield 'no_formatter' => [
            'fileLinkFormat' => null,
            'envDisabled' => false,
            'absolutePath' => '/my/absolute/path',
            'relativePath' => './my/relative/path',
            'expectedOutput' => "\033]8;;file:///my/absolute/path#L1\033\\./my/relative/path\033]8;;\033\\",
        ];

        yield 'sublime_formatter' => [
            'fileLinkFormat' => 'sublime',
            'envDisabled' => false,
            'absolutePath' => '/my/absolute/path',
            'relativePath' => './my/relative/path',
            'expectedOutput' => "\033]8;;subl://open?url=file:///my/absolute/path&line=1\033\\./my/relative/path\033]8;;\033\\",
        ];

        yield 'custom_formatter' => [
            'fileLinkFormat' => 'phpstorm://open?file=%f&line=%l',
            'envDisabled' => false,
            'absolutePath' => '/my/absolute/path',
            'relativePath' => './my/relative/path',
            'expectedOutput' => "\033]8;;phpstorm://open?file=/my/absolute/path&line=1\033\\./my/relative/path\033]8;;\033\\",
        ];

        yield 'env_disabled' => [
            'fileLinkFormat' => 'sublime',
            'envDisabled' => true,
            'absolutePath' => '/my/absolute/path',
            'relativePath' => './my/relative/path',
            'expectedOutput' => './my/relative/path',
        ];
    }

    #[DataProvider('provideMakeLinkedPath')]
    public function testMakeLinkedPath(
        ?string $fileLinkFormat,
        bool $envDisabled,
        string $absolutePath,
        string $relativePath,
        string $expectedOutput
    ): void {
        // Set environment variable
        if ($envDisabled) {
            putenv('MAKER_DISABLE_FILE_LINKS=1');
        } else {
            putenv('MAKER_DISABLE_FILE_LINKS=0');
        }

        $sut = new MakerFileLinkFormatter($fileLinkFormat);
        $result = $sut->makeLinkedPath($absolutePath, $relativePath);

        // Use binary-safe comparison to avoid PHPUnit display issues with ANSI sequences
        $this->assertSame(
            bin2hex($expectedOutput),
            bin2hex($result),
            sprintf(
                'Expected and actual hex representations differ. Expected length: %d, Actual length: %d',
                strlen($expectedOutput),
                strlen($result)
            )
        );
    }
}
