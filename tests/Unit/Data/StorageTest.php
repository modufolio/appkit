<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\PHP;
use Modufolio\Appkit\Data\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Storage::class)]
class StorageTest extends TestCase
{
    protected string $tmp;

    public function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-storage-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    protected function storage(array $data = []): Storage
    {
        $file = $this->tmp.'/storage.php';
        PHP::write($file, $data);

        return new Storage($file);
    }

    public function testConstructReadsFile(): void
    {
        $storage = $this->storage(['name' => 'Homer']);

        $this->assertSame(['name' => 'Homer'], $storage->data);
    }

    public function testInsertSingleKey(): void
    {
        $storage = $this->storage();

        $result = $storage->insert('name', 'Homer');

        $this->assertSame($storage, $result);
        $this->assertSame('Homer', $storage->get('name'));
    }

    public function testInsertArray(): void
    {
        $storage = $this->storage(['a' => 1]);

        $storage->insert(['b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $storage->get());
    }

    public function testSave(): void
    {
        $storage = $this->storage();
        $storage->insert('saved', true)->save();

        $reloaded = new Storage($storage->filePath);

        $this->assertTrue($reloaded->get('saved'));
    }

    public function testGetAll(): void
    {
        $storage = $this->storage(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $storage->get());
    }

    public function testGetColumn(): void
    {
        $storage = $this->storage([
            ['id' => 1, 'name' => 'Homer'],
            ['id' => 2, 'name' => 'Marge'],
        ]);

        $this->assertSame(['Homer', 'Marge'], $storage->get('name'));
    }

    public function testGetWithDefault(): void
    {
        $storage = $this->storage(['a' => 1]);

        $this->assertSame('fallback', $storage->get('missing', 'fallback'));
    }

    public function testRemoveSingleKey(): void
    {
        $storage = $this->storage(['a' => 1, 'b' => 2]);

        $this->assertSame(['b' => 2], $storage->remove('a'));
    }

    public function testRemoveAll(): void
    {
        $storage = $this->storage(['a' => 1, 'b' => 2]);

        $this->assertSame([], $storage->remove());
        $this->assertSame([], $storage->data);
    }
}
