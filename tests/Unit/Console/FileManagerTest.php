<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console;

use Composer\Autoload\ClassLoader;
use Modufolio\Appkit\Console\FileManager;
use Modufolio\Appkit\Util\AutoloaderUtil;
use Modufolio\Appkit\Util\MakerFileLinkFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FileManager::class)]
class FileManagerTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->rootDir.'/templates', 0777, true);
        mkdir($this->rootDir.'/src', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->rootDir);
    }

    private function createFileManager(?string $twigDefaultPath = null): FileManager
    {
        $classLoader = new ClassLoader();
        $classLoader->addPsr4('App\\', $this->rootDir.'/src');

        return new FileManager(
            new Filesystem(),
            new AutoloaderUtil($classLoader),
            new MakerFileLinkFormatter(),
            $this->rootDir,
            $twigDefaultPath,
        );
    }

    public function testConstructorNormalizesRootDirectory(): void
    {
        $classLoader = new ClassLoader();
        $fileManager = new FileManager(
            new Filesystem(),
            new AutoloaderUtil($classLoader),
            new MakerFileLinkFormatter(),
            $this->rootDir.'/src/../',
        );

        $this->assertSame($this->rootDir, $fileManager->getRootDirectory());
    }

    public function testConstructorThrowsForUnresolvablePath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Problem making path relative/');

        new FileManager(
            new Filesystem(),
            new AutoloaderUtil(new ClassLoader()),
            new MakerFileLinkFormatter(),
            '../relative-root',
        );
    }

    public function testParseTemplate(): void
    {
        $templatePath = $this->rootDir.'/greeting.tpl.php';
        file_put_contents($templatePath, 'Hello <?= $name ?>!');

        $result = $this->createFileManager()->parseTemplate($templatePath, ['name' => 'World']);

        $this->assertSame('Hello World!', $result);
    }

    public function testDumpFileCreatesUpdatesAndDetectsNoChange(): void
    {
        $fileManager = $this->createFileManager();
        $output = new BufferedOutput(decorated: false);
        $fileManager->setIO(new SymfonyStyle(new ArrayInput([]), $output));

        $fileManager->dumpFile('src/Foo.php', 'first');
        $this->assertStringContainsString('created', $output->fetch());
        $this->assertSame('first', file_get_contents($this->rootDir.'/src/Foo.php'));

        $fileManager->dumpFile('src/Foo.php', 'second');
        $this->assertStringContainsString('updated', $output->fetch());

        $fileManager->dumpFile('src/Foo.php', 'second');
        $this->assertStringContainsString('no change', $output->fetch());
    }

    public function testDumpFileWithoutIo(): void
    {
        $fileManager = $this->createFileManager();
        $fileManager->dumpFile('src/Bar.php', 'contents');

        $this->assertSame('contents', file_get_contents($this->rootDir.'/src/Bar.php'));
    }

    public function testFileExists(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertFalse($fileManager->fileExists('src/Nope.php'));
        file_put_contents($this->rootDir.'/src/Yep.php', 'x');
        $this->assertTrue($fileManager->fileExists('src/Yep.php'));
    }

    public function testRelativizePath(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertSame('src/Foo.php', $fileManager->relativizePath($this->rootDir.'/src/Foo.php'));
        // directory paths get a trailing slash
        $this->assertSame('src/', $fileManager->relativizePath($this->rootDir.'/src'));
        // paths outside the root are returned unchanged
        $this->assertSame('/other/place/Foo.php', $fileManager->relativizePath('/other/place/Foo.php'));
        // windows style slashes are normalized
        $this->assertSame(
            'src/Foo.php',
            $fileManager->relativizePath(str_replace('/', '\\', $this->rootDir).'\\src\\Foo.php')
        );
        // '../' segments are resolved
        $this->assertSame(
            'src/Foo.php',
            $fileManager->relativizePath($this->rootDir.'/templates/../src/./Foo.php')
        );
    }

    public function testGetFileContents(): void
    {
        file_put_contents($this->rootDir.'/src/Read.php', 'the contents');

        $this->assertSame('the contents', $this->createFileManager()->getFileContents('src/Read.php'));
    }

    public function testGetFileContentsThrowsWhenMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot find file "src/Missing.php"');

        $this->createFileManager()->getFileContents('src/Missing.php');
    }

    public function testIsPathInVendor(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertTrue($fileManager->isPathInVendor($this->rootDir.'/vendor/foo/bar.php'));
        $this->assertFalse($fileManager->isPathInVendor($this->rootDir.'/src/Foo.php'));
    }

    public function testAbsolutizePath(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertSame('/already/absolute', $fileManager->absolutizePath('/already/absolute'));
        $this->assertSame('C:\\windows\\path', $fileManager->absolutizePath('C:\\windows\\path'));
        $this->assertSame('C:/windows/path', $fileManager->absolutizePath('C:/windows/path'));
        $this->assertSame($this->rootDir.'/src/Foo.php', $fileManager->absolutizePath('src/Foo.php'));
    }

    public function testGetRelativePathForFutureClass(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertSame(
            'src/Entity/Product.php',
            $fileManager->getRelativePathForFutureClass('App\\Entity\\Product')
        );
        $this->assertNull($fileManager->getRelativePathForFutureClass('Unknown\\Namespace\\Thing'));
    }

    public function testGetNamespacePrefixForClass(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertSame('App\\', $fileManager->getNamespacePrefixForClass('App\\Entity\\Product'));
        $this->assertSame('', $fileManager->getNamespacePrefixForClass('Unknown\\Thing'));
    }

    public function testIsNamespaceConfiguredToAutoload(): void
    {
        $fileManager = $this->createFileManager();

        $this->assertTrue($fileManager->isNamespaceConfiguredToAutoload('App\\Entity'));
        $this->assertFalse($fileManager->isNamespaceConfiguredToAutoload('Unknown\\Namespace'));
    }

    public function testGetPathForTemplate(): void
    {
        $fileManager = $this->createFileManager($this->rootDir.'/templates');

        $this->assertSame('templates/base.html.php', $fileManager->getPathForTemplate('base.html.php'));
    }

    public function testGetPathForTemplateThrowsWithoutTwigPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot get path for template: is Twig installed?');

        $this->createFileManager()->getPathForTemplate('base.html.php');
    }
}
