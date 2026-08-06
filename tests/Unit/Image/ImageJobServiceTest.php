<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\ImageJobRepositoryInterface;
use Modufolio\Appkit\Image\ImageJobService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class InMemoryImageJobRepository implements ImageJobRepositoryInterface
{
    public array $jobs = [];

    public function saveJob(string $mediaRoot, string $thumbName, array $options): void
    {
        $this->jobs[$mediaRoot.'/'.$thumbName] = $options;
    }

    public function loadJob(string $mediaRoot, string $thumbName): ?array
    {
        return $this->jobs[$mediaRoot.'/'.$thumbName] ?? null;
    }

    public function deleteJob(string $mediaRoot, string $thumbName): bool
    {
        $key = $mediaRoot.'/'.$thumbName;

        if (!isset($this->jobs[$key])) {
            return false;
        }

        unset($this->jobs[$key]);

        return true;
    }

    public function jobExists(string $mediaRoot, string $thumbName): bool
    {
        return isset($this->jobs[$mediaRoot.'/'.$thumbName]);
    }
}

class ThrowingImageJobRepository implements ImageJobRepositoryInterface
{
    public function saveJob(string $mediaRoot, string $thumbName, array $options): void
    {
        throw new \RuntimeException('db down');
    }

    public function loadJob(string $mediaRoot, string $thumbName): ?array
    {
        throw new \RuntimeException('db down');
    }

    public function deleteJob(string $mediaRoot, string $thumbName): bool
    {
        throw new \RuntimeException('db down');
    }

    public function jobExists(string $mediaRoot, string $thumbName): bool
    {
        throw new \RuntimeException('db down');
    }
}

#[CoversClass(ImageJobService::class)]
class ImageJobServiceTest extends TestCase
{
    public function testSaveLoadDeleteAndExists(): void
    {
        $repository = new InMemoryImageJobRepository();
        $service = new ImageJobService($repository);

        $service->saveJob('/media/abc', 'a-300x200.png', ['width' => 300, 'height' => 200]);

        $this->assertTrue($service->jobExists('/media/abc', 'a-300x200.png'));
        $this->assertSame(
            ['width' => 300, 'height' => 200],
            $service->loadJob('/media/abc', 'a-300x200.png')
        );

        $this->assertTrue($service->deleteJob('/media/abc', 'a-300x200.png'));
        $this->assertFalse($service->deleteJob('/media/abc', 'a-300x200.png'));
        $this->assertFalse($service->jobExists('/media/abc', 'a-300x200.png'));
        $this->assertNull($service->loadJob('/media/abc', 'a-300x200.png'));
    }

    public function testRepositoryFailuresAreSwallowed(): void
    {
        $service = new ImageJobService(new ThrowingImageJobRepository());

        $service->saveJob('/media/abc', 'a.png', []);

        $this->assertNull($service->loadJob('/media/abc', 'a.png'));
        $this->assertFalse($service->deleteJob('/media/abc', 'a.png'));
        $this->assertFalse($service->jobExists('/media/abc', 'a.png'));
    }
}
