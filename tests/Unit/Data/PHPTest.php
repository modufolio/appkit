<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\PHP;
use Modufolio\Appkit\Toolkit\F;
use BadMethodCallException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Appkit\Data\PHP
 */
class PHPTest extends TestCase
{
    /**
     * @covers ::encode
     */
    public function testEncode(): void
    {
        $input    = __DIR__ . '/fixtures/php/input.php';
        $expected = __DIR__ . '/fixtures/php/expected.php';
        $result   = PHP::encode(include $input);

        $this->assertSame(trim(file_get_contents($expected)), $result);

        // scalar values
        $this->assertSame("'test'", PHP::encode('test'));
        $this->assertSame('123', PHP::encode(123));
    }

    /**
     * @covers ::decode
     */
    public function testDecode(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('The PHP::decode() method is not implemented');

        $input  = include __DIR__ . '/fixtures/php/input.php';
        $result = PHP::decode($input);
    }

    /**
     * @covers ::read
     * @throws \Exception
     */
    public function testRead(): void
    {
        $input  = __DIR__ . '/fixtures/php/input.php';
        $result = PHP::read($input);

        $this->assertSame($result, include $input);
    }

    /**
     * @covers ::read
     */
    public function testReadFileMissing(): void
    {
        $file = __DIR__ . '/tmp/does-not-exist.php';

        $this->expectException('Exception');
        $this->expectExceptionMessage('The file "' . $file . '" does not exist');

        PHP::read($file);
    }

    /**
     * @covers ::write
     */
    public function testWrite(): void
    {
        $input = include __DIR__ . '/fixtures/php/input.php';
        $file  = __DIR__ . '/fixtures/php/tmp.php';

        $this->assertTrue(PHP::write($file, $input));

        $this->assertSame($input, include $file);
        $this->assertSame($input, PHP::read($file));

        F::remove($file);
    }
}
