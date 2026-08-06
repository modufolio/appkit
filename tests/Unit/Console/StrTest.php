<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console;

use Modufolio\Appkit\Console\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Str::class)]
class StrTest extends TestCase
{
    public function testHasSuffix(): void
    {
        $this->assertTrue(Str::hasSuffix('FooCommand', 'Command'));
        $this->assertTrue(Str::hasSuffix('Foocommand', 'Command'));
        $this->assertFalse(Str::hasSuffix('FooController', 'Command'));
    }

    public function testAddSuffix(): void
    {
        $this->assertSame('FooCommand', Str::addSuffix('Foo', 'Command'));
        $this->assertSame('FooCommand', Str::addSuffix('Foocommand', 'Command'));
        $this->assertSame('FooCommand', Str::addSuffix('FooCommand', 'Command'));
    }

    public function testRemoveSuffix(): void
    {
        $this->assertSame('Foo', Str::removeSuffix('FooCommand', 'Command'));
        $this->assertSame('Foo', Str::removeSuffix('Foo', 'Command'));
        $this->assertSame('FooCommand', Str::removeSuffix('FooCommandCommand', 'Command'));
    }

    public function testAsClassName(): void
    {
        $this->assertSame('AppDoThisAndThat', Str::asClassName('app:do_this-and.that'));
        $this->assertSame('FooCommand', Str::asClassName('foo', 'Command'));
        $this->assertSame('FooBar', Str::asClassName(' foo bar '));
    }

    public function testAsTwigVariable(): void
    {
        $this->assertSame('blog_post_type', Str::asTwigVariable('BlogPostType'));
        $this->assertSame('foo_bar', Str::asTwigVariable('foo bar'));
        $this->assertSame('foo_bar', Str::asTwigVariable('foo--bar'));
    }

    public function testAsLowerCamelCase(): void
    {
        $this->assertSame('blogPost', Str::asLowerCamelCase('blog_post'));
    }

    public function testAsCamelCase(): void
    {
        $this->assertSame('BlogPost', Str::asCamelCase('blog_post'));
        $this->assertSame('FooBarBaz', Str::asCamelCase('foo.bar\\baz'));
    }

    public function testAsRoutePath(): void
    {
        $this->assertSame('/blog/post', Str::asRoutePath('BlogPost'));
    }

    public function testAsRouteName(): void
    {
        $this->assertSame('app_blog_post', Str::asRouteName('BlogPost'));
        $this->assertSame('app_blog', Str::asRouteName('app_blog'));
    }

    public function testAsSnakeCase(): void
    {
        $this->assertSame('blog_post', Str::asSnakeCase('BlogPost'));
    }

    public function testAsCommand(): void
    {
        $this->assertSame('app-blog-post', Str::asCommand('AppBlogPost'));
    }

    public function testAsEventMethod(): void
    {
        $this->assertSame('onKernelRequest', Str::asEventMethod('kernel.request'));
    }

    public function testGetShortClassName(): void
    {
        $this->assertSame('Bar', Str::getShortClassName('Foo\\Bar'));
        $this->assertSame('Foo', Str::getShortClassName('Foo'));
    }

    public function testGetHumanDiscriminatorBetweenTwoClasses(): void
    {
        $this->assertSame(
            ['Entity', 'Model'],
            Str::getHumanDiscriminatorBetweenTwoClasses('App\\Entity\\User', 'App\\Model\\User')
        );

        // one class has no namespace
        $this->assertSame(
            ['', 'App\\Model'],
            Str::getHumanDiscriminatorBetweenTwoClasses('User', 'App\\Model\\User')
        );

        // identical namespaces
        $this->assertSame(
            ['', ''],
            Str::getHumanDiscriminatorBetweenTwoClasses('App\\Entity\\User', 'App\\Entity\\Post')
        );
    }

    public function testGetNamespace(): void
    {
        $this->assertSame('App\\Entity', Str::getNamespace('App\\Entity\\User'));
        $this->assertSame('', Str::getNamespace('User'));
    }

    public function testSingularCamelCaseToPluralCamelCase(): void
    {
        $this->assertSame('blogPosts', Str::singularCamelCaseToPluralCamelCase('blogPost'));
        $this->assertSame('categories', Str::singularCamelCaseToPluralCamelCase('category'));
    }

    public function testPluralCamelCaseToSingular(): void
    {
        $this->assertSame('blogPost', Str::pluralCamelCaseToSingular('blogPosts'));
        $this->assertSame('category', Str::pluralCamelCaseToSingular('categories'));
    }

    public function testGetRandomTerm(): void
    {
        $term = Str::getRandomTerm();
        $this->assertMatchesRegularExpression('/^[a-z]+ [a-z]+$/', $term);
    }

    public function testIsValidPhpVariableName(): void
    {
        $this->assertTrue(Str::isValidPhpVariableName('foo'));
        $this->assertTrue(Str::isValidPhpVariableName('_foo9'));
        $this->assertFalse(Str::isValidPhpVariableName('9foo'));
        $this->assertFalse(Str::isValidPhpVariableName('foo-bar'));
    }

    public function testAreClassesAlphabetical(): void
    {
        $this->assertTrue(Str::areClassesAlphabetical('Apple', 'Banana'));
        $this->assertFalse(Str::areClassesAlphabetical('Banana', 'Apple'));
    }

    public function testAsHumanWords(): void
    {
        $this->assertSame('Foo Bar Baz', Str::asHumanWords('fooBarBaz'));
        $this->assertSame('Foo', Str::asHumanWords('foo'));
    }
}
