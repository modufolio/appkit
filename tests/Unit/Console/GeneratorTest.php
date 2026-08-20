<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console;

use Composer\Autoload\ClassLoader;
use Modufolio\Appkit\Console\FileManager;
use Modufolio\Appkit\Console\Generator;
use Modufolio\Appkit\Exception\RuntimeCommandException;
use Modufolio\Appkit\Util\AutoloaderUtil;
use Modufolio\Appkit\Util\ClassSource\Model\ClassData;
use Modufolio\Appkit\Util\MakerFileLinkFormatter;
use Modufolio\Appkit\Util\TemplateComponentGenerator;
use Modufolio\Appkit\Util\UseStatementGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(Generator::class)]
class GeneratorTest extends TestCase
{
    /** @var non-empty-string */
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->rootDir.'/src', 0777, true);
        mkdir($this->rootDir.'/templates', 0777, true);
        file_put_contents(
            $this->rootDir.'/simple.tpl.php',
            'class: <?= $class_name ?? \'-\' ?>, path: <?= $relative_path ?>'
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->rootDir);
    }

    private function createGenerator(?TemplateComponentGenerator $templateComponentGenerator = null): Generator
    {
        $classLoader = new ClassLoader();
        $classLoader->addPsr4('App\\', $this->rootDir.'/src');

        $fileManager = new FileManager(
            new Filesystem(),
            new AutoloaderUtil($classLoader),
            new MakerFileLinkFormatter(),
            $this->rootDir,
            $this->rootDir.'/templates',
        );

        return new Generator($fileManager, 'App\\', null, $templateComponentGenerator);
    }

    /**
     * @return array<string, mixed>
     */
    private function entityTemplateVariables(): array
    {
        return [
            'use_statements' => new UseStatementGenerator([
                'App\\Repository\\ProductRepository',
                ['Doctrine\\ORM\\Mapping' => 'ORM'],
            ]),
            'repository_class_name' => 'ProductRepository',
            'should_escape_table_name' => false,
            'table_name' => 'product',
        ];
    }

    public function testGenerateClassAndWriteChanges(): void
    {
        $generator = $this->createGenerator();

        $this->assertFalse($generator->hasPendingOperations());

        $path = $generator->generateClass(
            'App\\Entity\\Product',
            'doctrine/Entity.tpl.php',
            $this->entityTemplateVariables()
        );

        $this->assertSame('src/Entity/Product.php', $path);
        $this->assertTrue($generator->hasPendingOperations());

        $contents = $generator->getFileContentsForPendingOperation($path);
        $this->assertStringContainsString('namespace App\\Entity;', $contents);
        $this->assertStringContainsString('class Product', $contents);

        $generator->writeChanges();

        $this->assertFalse($generator->hasPendingOperations());
        $this->assertSame(['src/Entity/Product.php'], $generator->getGeneratedFiles());
        $this->assertFileExists($this->rootDir.'/src/Entity/Product.php');
    }

    public function testGenerateClassThrowsForUnknownNamespace(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Could not determine where to locate the new class/');

        $this->createGenerator()->generateClass('Unknown\\Namespace\\Thing', 'doctrine/Entity.tpl.php');
    }

    public function testGenerateClassWithClassData(): void
    {
        $generator = $this->createGenerator(new TemplateComponentGenerator(false, false, 'App'));

        $path = $generator->generateClass(
            'ignored',
            $this->rootDir.'/simple.tpl.php',
            ['class_data' => ClassData::create('Entity\\Widget')]
        );

        $this->assertSame('src/Entity/Widget.php', $path);
    }

    public function testGenerateClassThrowsWhenFileAlreadyExists(): void
    {
        mkdir($this->rootDir.'/src/Entity', 0777, true);
        file_put_contents($this->rootDir.'/src/Entity/Product.php', 'existing');

        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('The file "src/Entity/Product.php" can\'t be generated because it already exists.');

        $this->createGenerator()->generateClass('App\\Entity\\Product', 'doctrine/Entity.tpl.php');
    }

    public function testGenerateClassThrowsForMissingTemplate(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot find template "does/not/exist.tpl.php"');

        $this->createGenerator()->generateClass('App\\Entity\\Product', 'does/not/exist.tpl.php');
    }

    public function testGenerateFile(): void
    {
        $generator = $this->createGenerator();

        $generator->generateFile('config/thing.txt', $this->rootDir.'/simple.tpl.php', []);
        $generator->writeChanges();

        $this->assertStringContainsString(
            'path: config/thing.txt',
            (string) file_get_contents($this->rootDir.'/config/thing.txt')
        );
    }

    public function testDumpFile(): void
    {
        $generator = $this->createGenerator();

        $generator->dumpFile('config/raw.txt', 'raw contents');
        $generator->writeChanges();

        $this->assertSame('raw contents', file_get_contents($this->rootDir.'/config/raw.txt'));
    }

    public function testGetFileContentsForPendingOperationThrowsForUnknownPath(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('File "nope.php" is not in the Generator\'s pending operations');

        $this->createGenerator()->getFileContentsForPendingOperation('nope.php');
    }

    public function testCreateClassNameDetails(): void
    {
        $generator = $this->createGenerator();

        $details = $generator->createClassNameDetails('foo', 'Controller\\', 'Controller');
        $this->assertSame('App\\Controller\\FooController', $details->getFullName());
        $this->assertSame('FooController', $details->getShortName());

        $details = $generator->createClassNameDetails('featured product', 'Entity\\');
        $this->assertSame('App\\Entity\\FeaturedProduct', $details->getFullName());
    }

    public function testCreateClassNameDetailsWithAbsoluteClassName(): void
    {
        $details = $this->createGenerator()->createClassNameDetails('\\App\\Custom\\Thing', 'Entity\\');

        $this->assertSame('App\\Custom\\Thing', $details->getFullName());
    }

    public function testCreateClassNameDetailsWithExistingClassOutsideNamespace(): void
    {
        // \DateTime already exists, so the namespace prefix is not prepended
        $details = $this->createGenerator()->createClassNameDetails('DateTime', 'Entity\\');

        $this->assertSame('DateTime', $details->getFullName());
    }

    public function testRootAccessors(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame($this->rootDir, $generator->getRootDirectory());
        $this->assertSame('App', $generator->getRootNamespace());
    }

    public function testGenerateController(): void
    {
        $generator = $this->createGenerator(new TemplateComponentGenerator(false, false, 'App'));

        $path = $generator->generateController(
            'App\\Controller\\DemoController',
            $this->rootDir.'/simple.tpl.php'
        );

        $this->assertSame('src/Controller/DemoController.php', $path);

        $generator->writeChanges();

        $this->assertStringContainsString(
            'class: DemoController',
            (string) file_get_contents($this->rootDir.'/src/Controller/DemoController.php')
        );
    }

    public function testGenerateTemplate(): void
    {
        $generator = $this->createGenerator();

        $generator->generateTemplate('demo/index.html.php', $this->rootDir.'/simple.tpl.php');
        $generator->writeChanges();

        $this->assertFileExists($this->rootDir.'/templates/demo/index.html.php');
    }
}
