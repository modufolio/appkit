<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Util\ClassSource\Model\ClassData;
use Modufolio\Appkit\Util\TemplateComponentGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TemplateComponentGeneratorTest extends TestCase
{
    public function testRouteAttributes(): void
    {
        $generator = new TemplateComponentGenerator(false, false, 'App');

        $expected = "    #[Route('/', name: 'app_home')]\n";

        self::assertSame(
            $expected,
            $generator->generateRouteForControllerMethod('/', 'app_home')
        );
    }

    #[DataProvider('routeMethodDataProvider')]
    public function testRouteMethods(string $expected, array $methods): void
    {
        $generator = new TemplateComponentGenerator(false, false, 'App');

        self::assertSame(
            $expected,
            $generator->generateRouteForControllerMethod('/', 'app_home', $methods)
        );
    }

    public static function routeMethodDataProvider(): \Generator
    {
        yield ["    #[Route('/', name: 'app_home', methods: ['GET'])]\n", ['GET']];
        yield ["    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]\n", ['GET', 'POST']];
    }

    #[DataProvider('routeIndentationDataProvider')]
    public function testRouteIndentation(string $expected): void
    {
        $generator = new TemplateComponentGenerator(false, false, 'App');

        self::assertSame(
            $expected,
            $generator->generateRouteForControllerMethod('/', 'app_home', [], false)
        );
    }

    public static function routeIndentationDataProvider(): \Generator
    {
        yield ["#[Route('/', name: 'app_home')]\n"];
    }

    #[DataProvider('routeTrailingNewLineDataProvider')]
    public function testRouteTrailingNewLine(string $expected): void
    {
        $generator = new TemplateComponentGenerator(false, false, 'App');

        self::assertSame(
            $expected,
            $generator->generateRouteForControllerMethod('/', 'app_home', [], false, false)
        );
    }

    public static function routeTrailingNewLineDataProvider(): \Generator
    {
        yield ["#[Route('/', name: 'app_home')]"];
    }

    #[DataProvider('finalClassDataProvider')]
    public function testGetFinalClassDeclaration(
        bool $finalClass,
        bool $finalEntity,
        bool $isEntity,
        string $expectedResult
    ): void {
        $generator = new TemplateComponentGenerator($finalClass, $finalEntity, 'App');

        // Use a generic example class instead of Symfony MakerBundle
        $classData = ClassData::create('Example\\MyClass', isEntity: $isEntity);

        $generator->configureClass($classData);

        self::assertSame(
            sprintf('%sclass MyClass', $expectedResult),
            $classData->getClassDeclaration()
        );
    }

    public static function finalClassDataProvider(): \Generator
    {
        yield 'Not Final Class' => [false, false, false, ''];
        yield 'Not Final Class w/ Entity' => [false, true, false, ''];
        yield 'Final Class' => [true, false, false, 'final '];
        yield 'Final Class w/ Entity' => [true, true, false, 'final '];
        yield 'Not Final Entity' => [false, false, true, ''];
        yield 'Not Final Entity w/ Class' => [true, false, true, ''];
        yield 'Final Entity' => [false, true, true, 'final '];
        yield 'Final Entity w/ Class' => [true, true, true, 'final '];
    }

    public function testConfiguresClassDataWithRootNamespace(): void
    {
        $generator = new TemplateComponentGenerator(false, false, 'CustomApp');

        $classData = ClassData::create('Example\\MyClass');

        $generator->configureClass($classData);

        self::assertSame('CustomApp\Example', $classData->getNamespace());
    }
}
