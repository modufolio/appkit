<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Util\ClassSource;

use Modufolio\Appkit\Util\ClassSource\Model\ClassData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassDataTest extends TestCase
{
    public function testStaticConstructor(): void
    {
        $meta = ClassData::create('Controller\\Foo');

        self::assertSame('Foo', $meta->getClassName());
        self::assertSame('App\Controller', $meta->getNamespace());
        self::assertSame('App\Controller\Foo', $meta->getFullClassName());
    }

    public function testGetClassDeclaration(): void
    {
        $meta = ClassData::create('MyService');

        self::assertSame('final class MyService', $meta->getClassDeclaration());
    }

    public function testIsFinal(): void
    {
        $meta = ClassData::create('MyService');

        // Default: final
        self::assertSame('final class MyService', $meta->getClassDeclaration());

        // Set not final
        $meta->setIsFinal(false);
        self::assertSame('class MyService', $meta->getClassDeclaration());
    }

    public function testGetClassDeclarationWithExtends(): void
    {
        $meta = ClassData::create('MyService', extendsClass: 'Vendor\\Package\\BaseClass');

        self::assertSame('final class MyService extends BaseClass', $meta->getClassDeclaration());
    }

    #[DataProvider('suffixDataProvider')]
    public function testSuffix(?string $suffix, string $expectedResult): void
    {
        $meta = ClassData::create('MyService', suffix: $suffix);

        self::assertSame($expectedResult, $meta->getClassName());
    }

    public static function suffixDataProvider(): \Generator
    {
        yield [null, 'MyService'];
        yield ['Manager', 'MyServiceManager'];
        yield ['Service', 'MyService'];
    }

    #[DataProvider('namespaceDataProvider')]
    public function testNamespace(string $class, ?string $rootNamespace, string $expectedNamespace, string $expectedFullClassName): void
    {
        $meta = ClassData::create($class);

        if ($rootNamespace !== null) {
            $meta->setRootNamespace($rootNamespace);
        }

        self::assertSame($expectedNamespace, $meta->getNamespace());
        self::assertSame($expectedFullClassName, $meta->getFullClassName());
    }

    public static function namespaceDataProvider(): \Generator
    {
        yield ['MyController', null, 'App', 'App\MyController'];
        yield ['Controller\MyController', null, 'App\Controller', 'App\Controller\MyController'];
        yield ['MyController', 'Custom', 'Custom', 'Custom\MyController'];
        yield ['Controller\MyController', 'Custom', 'Custom\Controller', 'Custom\Controller\MyController'];
    }

    public function testAddUseStatements(): void
    {
        $meta = ClassData::create('Foo');
        $meta->addUseStatement('Vendor\\Package\\SomeClass');

        $useStatements = $meta->getUseStatements();

        self::assertStringContainsString('use Vendor\Package\SomeClass;', $useStatements);
    }
}
