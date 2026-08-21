<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\Handler;
use Modufolio\Appkit\Toolkit\F;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/mocks.php';

#[CoversClass(Handler::class)]
class HandlerTest extends TestCase
{
    /**
     * Per-test scratch directory. Tests must not share a fixed path: under
     * ParaTest sibling workers run this class concurrently, and a shared file
     * would have one worker unlink what another is asserting on.
     */
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-handler-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmp);
    }

    public function testReadWrite(): void
    {
        $data = [
            'name' => 'Homer Simpson',
            'email' => 'homer@simpson.com',
        ];

        $file = $this->tmp.'/data.json';

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
        $file = $this->tmp.'/does-not-exist.json';

        $this->expectException('Exception');

        CustomHandler::read($file);
    }
}
