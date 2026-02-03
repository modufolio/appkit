<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Disk;
use Modufolio\Appkit\Image\DiskManager;
use PHPUnit\Framework\TestCase;

class DiskManagerTest extends TestCase
{
    private DiskManager $diskManager;

    protected function setUp(): void
    {
        $this->diskManager = new DiskManager();
    }

    public function testDefaultDiskExists(): void
    {
        $disk = $this->diskManager->disk('default');
        $this->assertSame('default', $disk->name());
    }

    public function testRegisterDisk(): void
    {
        $disk = new Disk('avatars', '/uploads/avatars', 'https://example.com/avatars');
        $this->diskManager->register($disk);

        $retrievedDisk = $this->diskManager->disk('avatars');
        $this->assertSame('avatars', $retrievedDisk->name());
    }

    public function testRegisterMultipleDisks(): void
    {
        $this->diskManager->registerMultiple([
            'avatars' => [
                'root' => '/uploads/avatars',
                'url' => 'https://example.com/avatars',
            ],
            'products' => [
                'root' => '/uploads/products',
                'url' => 'https://example.com/products',
            ],
        ]);

        $this->assertTrue($this->diskManager->has('avatars'));
        $this->assertTrue($this->diskManager->has('products'));
        $this->assertSame('avatars', $this->diskManager->disk('avatars')->name());
        $this->assertSame('products', $this->diskManager->disk('products')->name());
    }

    public function testGetAllDisks(): void
    {
        $this->diskManager->registerMultiple([
            'avatars' => ['root' => '/uploads/avatars'],
            'products' => ['root' => '/uploads/products'],
        ]);

        $all = $this->diskManager->all();
        $this->assertCount(3, $all); // default + avatars + products
    }

    public function testHasDisk(): void
    {
        $this->assertTrue($this->diskManager->has('default'));
        $this->assertFalse($this->diskManager->has('nonexistent'));
    }

    public function testGetNonexistentDiskThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->diskManager->disk('nonexistent');
    }

    public function testSetDefaultDisk(): void
    {
        $disk = new Disk('custom', '/uploads/custom', 'https://example.com/custom');
        $this->diskManager->register($disk);
        $this->diskManager->setDefault('custom');

        $default = $this->diskManager->getDefault();
        $this->assertSame('custom', $default->name());
    }

    public function testCreateDiskFromArray(): void
    {
        $config = [
            'root' => '/uploads/documents',
            'url' => 'https://example.com/docs',
            'description' => 'Document storage',
        ];

        $disk = DiskManager::createDisk('documents', $config);

        $this->assertSame('documents', $disk->name());
        $this->assertSame('/uploads/documents', $disk->root());
        $this->assertSame('https://example.com/docs', $disk->url());
        $this->assertSame('Document storage', $disk->config()['description']);
    }

    public function testCreateDiskWithDefaults(): void
    {
        $config = [
            'root' => '/uploads/test',
        ];

        $disk = DiskManager::createDisk('test', $config);

        $this->assertSame('test', $disk->name());
        $this->assertSame('/uploads/test', $disk->root());
        $this->assertSame('', $disk->url());
    }
}
