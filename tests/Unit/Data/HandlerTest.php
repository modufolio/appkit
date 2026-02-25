<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\Handler;
use Modufolio\Appkit\Toolkit\F;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/mocks.php';

#[CoversClass(Handler::class)]
class HandlerTest extends TestCase
{
    public function testReadWrite(): void
    {
        $data = [
            'name'  => 'Homer Simpson',
            'email' => 'homer@simpson.com'
        ];

        $file = __DIR__ . '/tmp/data.json';

        // clean up first
        @unlink($file);

        CustomHandler::write($file, $data);
        $this->assertFileExists($file);
        $this->assertSame(CustomHandler::encode($data), F::read($file));

        $result = CustomHandler::read($file);
        $this->assertSame($data, $result);
    }

    public function testReadFileMissing(): void
    {
        $file = __DIR__ . '/tmp/does-not-exist.json';

        $this->expectException('Exception');

        CustomHandler::read($file);
    }
}
