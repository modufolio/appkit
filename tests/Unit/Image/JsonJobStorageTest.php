<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonJobStorage::class)]
class JsonJobStorageTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    public function testSaveAndLoadJob(): void
    {
        $storage = new JsonJobStorage();

        $storage->saveJob($this->tmp, 'photo-300x200.png', [
            'width' => 300,
            'height' => 200,
        ]);

        $this->assertFileExists($this->tmp.'/.jobs/photo-300x200.png.json');
        $this->assertTrue($storage->jobExists($this->tmp, 'photo-300x200.png'));
        $this->assertSame(
            ['width' => 300, 'height' => 200],
            $storage->loadJob($this->tmp, 'photo-300x200.png')
        );
    }

    public function testCustomJobsSubdirWithLeadingSlash(): void
    {
        $storage = new JsonJobStorage('/queue');

        $storage->saveJob($this->tmp, 'a.png', ['width' => 10]);

        $this->assertFileExists($this->tmp.'/queue/a.png.json');
        $this->assertTrue($storage->jobExists($this->tmp, 'a.png'));
    }

    public function testLoadJobReturnsNullForMissingJob(): void
    {
        $storage = new JsonJobStorage();

        $this->assertNull($storage->loadJob($this->tmp, 'missing.png'));
    }

    public function testLoadJobReturnsNullForInvalidJson(): void
    {
        $storage = new JsonJobStorage();
        mkdir($this->tmp.'/.jobs');
        file_put_contents($this->tmp.'/.jobs/broken.png.json', 'not-json{');

        $this->assertNull($storage->loadJob($this->tmp, 'broken.png'));
    }

    public function testDeleteJob(): void
    {
        $storage = new JsonJobStorage();
        $storage->saveJob($this->tmp, 'a.png', ['width' => 10]);

        $this->assertTrue($storage->deleteJob($this->tmp, 'a.png'));
        $this->assertFalse($storage->jobExists($this->tmp, 'a.png'));
        $this->assertFalse($storage->deleteJob($this->tmp, 'a.png'));
    }

    public function testSaveJobFailsSilently(): void
    {
        $storage = new JsonJobStorage();

        // mediaRoot points below a regular file, so the jobs dir can't be created
        $file = $this->tmp.'/blocker';
        file_put_contents($file, 'x');

        $storage->saveJob($file, 'a.png', ['width' => 10]);

        $this->assertFalse($storage->jobExists($file, 'a.png'));
    }

    public function testThumbNamePathComponentsAreStripped(): void
    {
        $storage = new JsonJobStorage();

        $storage->saveJob($this->tmp, 'nested/dir/a.png', ['width' => 10]);

        // basename() is applied to the thumb name
        $this->assertFileExists($this->tmp.'/.jobs/a.png.json');
        $this->assertTrue($storage->jobExists($this->tmp, 'a.png'));
    }
}
