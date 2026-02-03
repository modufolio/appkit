<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Disk;
use Modufolio\Appkit\Image\DiskInterface;
use PHPUnit\Framework\TestCase;

class DiskTest extends TestCase
{
    public function testDiskImplementsInterface(): void
    {
        $disk = new Disk('test', '/uploads/test', 'https://example.com/test');
        $this->assertInstanceOf(DiskInterface::class, $disk);
    }

    public function testDiskName(): void
    {
        $disk = new Disk('avatars', '/uploads/avatars', 'https://example.com/avatars');
        $this->assertSame('avatars', $disk->name());
    }

    public function testDiskRoot(): void
    {
        $disk = new Disk('avatars', '/uploads/avatars', 'https://example.com/avatars');
        $this->assertSame('/uploads/avatars', $disk->root());
    }

    public function testDiskUrl(): void
    {
        $disk = new Disk('avatars', '/uploads/avatars', 'https://example.com/avatars');
        $this->assertSame('https://example.com/avatars', $disk->url());
    }

    public function testDiskPathsAreTrimmed(): void
    {
        $disk = new Disk('test', '/uploads/test/', 'https://example.com/test/');
        $this->assertSame('/uploads/test', $disk->root());
        $this->assertSame('https://example.com/test', $disk->url());
    }

    public function testDiskWithoutUrl(): void
    {
        $disk = new Disk('test', '/uploads/test');
        $this->assertSame('', $disk->url());
    }

    public function testDiskConfig(): void
    {
        $disk = new Disk('avatars', '/uploads/avatars', 'https://example.com/avatars', ['custom' => 'value']);
        $config = $disk->config();

        $this->assertSame('avatars', $config['name']);
        $this->assertSame('/uploads/avatars', $config['root']);
        $this->assertSame('https://example.com/avatars', $config['url']);
        $this->assertSame('value', $config['custom']);
    }
}
