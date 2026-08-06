<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Modufolio\Appkit\Console\Validator;
use Modufolio\Appkit\Exception\RuntimeCommandException;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

enum ValidatorTestSuit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}

#[CoversClass(Validator::class)]
class ValidatorTest extends TestCase
{
    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testValidateClassNameValid(): void
    {
        $this->assertSame('App\\Entity\\User', Validator::validateClassName('App\\Entity\\User'));
        $this->assertSame('\\App\\Entity\\User', Validator::validateClassName('\\App\\Entity\\User'));
        $this->assertSame('_Foo9', Validator::validateClassName('_Foo9'));
    }

    public function testValidateClassNameInvalidCharacters(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/not valid as a PHP class name/');
        Validator::validateClassName('App\\9Invalid');
    }

    public function testValidateClassNameCustomErrorMessage(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('custom message');
        Validator::validateClassName('App\\9Invalid', 'custom message');
    }

    public function testValidateClassNameReservedKeyword(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/reserved keyword/');
        Validator::validateClassName('App\\Entity\\Class');
    }

    public function testValidateClassNameInvalidUtf8(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/not a UTF-8-encoded string/');
        Validator::validateClassName("App\\Foo\xc3\x28Bar\\Baz");
    }

    public function testNotBlank(): void
    {
        $this->assertSame('foo', Validator::notBlank('foo'));
    }

    public function testNotBlankThrowsOnNull(): void
    {
        $this->expectException(RuntimeCommandException::class);
        Validator::notBlank(null);
    }

    public function testNotBlankThrowsOnEmptyString(): void
    {
        $this->expectException(RuntimeCommandException::class);
        Validator::notBlank('');
    }

    public function testValidateLength(): void
    {
        $this->assertSame(null, Validator::validateLength(null));
        $this->assertSame('', Validator::validateLength(''));
        $this->assertSame(100, Validator::validateLength('100'));
        $this->assertSame(1, Validator::validateLength(1));
    }

    public function testValidateLengthInvalid(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('Invalid length "-1".');
        Validator::validateLength('-1');
    }

    public function testValidatePrecision(): void
    {
        $this->assertSame(null, Validator::validatePrecision(null));
        $this->assertSame(10, Validator::validatePrecision('10'));
        $this->assertSame(65, Validator::validatePrecision(65));
    }

    public function testValidatePrecisionInvalid(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('Invalid precision "66".');
        Validator::validatePrecision('66');
    }

    public function testValidateScale(): void
    {
        $this->assertSame(null, Validator::validateScale(null));
        $this->assertSame(2, Validator::validateScale('2'));
        $this->assertSame(30, Validator::validateScale(30));
    }

    public function testValidateScaleInvalid(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('Invalid scale "31".');
        Validator::validateScale('31');
    }

    public function testValidateBoolean(): void
    {
        $this->assertTrue(Validator::validateBoolean('yes'));
        $this->assertFalse(Validator::validateBoolean('no'));
        $this->assertTrue(Validator::validateBoolean('true'));
        $this->assertFalse(Validator::validateBoolean('false'));
        $this->assertTrue(Validator::validateBoolean('1'));
    }

    public function testValidateBooleanInvalid(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('Invalid bool value "banana".');
        Validator::validateBoolean('banana');
    }

    public function testValidatePropertyName(): void
    {
        $this->assertSame('firstName', Validator::validatePropertyName('firstName'));
    }

    public function testValidatePropertyNameInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"9foo" is not a valid PHP property name.');
        Validator::validatePropertyName('9foo');
    }

    public function testValidateDoctrineFieldName(): void
    {
        $this->assertSame('title', Validator::validateDoctrineFieldName('title', $this->connection()));
    }

    public function testValidateDoctrineFieldNameReservedWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name "order" is a reserved word.');
        Validator::validateDoctrineFieldName('order', $this->connection());
    }

    public function testValidateEmailAddress(): void
    {
        $this->assertSame('foo@example.com', Validator::validateEmailAddress('foo@example.com'));
    }

    public function testValidateEmailAddressInvalid(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('"nope" is not a valid email address.');
        Validator::validateEmailAddress('nope');
    }

    public function testExistsOrNullWithNull(): void
    {
        $this->assertNull(Validator::existsOrNull(null));
    }

    public function testExistsOrNullWithAbsoluteClass(): void
    {
        $className = '\\'.InMemoryUser::class;
        $this->assertSame($className, Validator::existsOrNull($className));
    }

    public function testExistsOrNullWithEntity(): void
    {
        $this->assertSame(
            'App\\Entity\\Product',
            Validator::existsOrNull('App\\Entity\\Product', ['App\\Entity\\Product'])
        );
    }

    public function testClassExists(): void
    {
        $this->assertSame(InMemoryUser::class, Validator::classExists(InMemoryUser::class));
    }

    public function testClassExistsThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/doesn\'t exist/');
        Validator::classExists('App\\Does\\Not\\Exist');
    }

    public function testClassExistsCustomMessage(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('nope');
        Validator::classExists('App\\Does\\Not\\Exist', 'nope');
    }

    public function testEntityExists(): void
    {
        $this->assertSame(
            'App\\Entity\\Product',
            Validator::entityExists('App\\Entity\\Product', ['App\\Entity\\Product'])
        );
    }

    public function testEntityExistsWithLeadingSlashExistingClass(): void
    {
        $className = '\\'.InMemoryUser::class;
        $this->assertSame(
            $className,
            Validator::entityExists($className, [InMemoryUser::class])
        );
    }

    public function testEntityExistsThrowsWithoutEntities(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('There are no registered entities');
        Validator::entityExists('App\\Entity\\Product', []);
    }

    public function testEntityExistsThrowsWhenNotRegistered(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/doesn\'t exist; please enter an existing one/');
        Validator::entityExists('App\\Entity\\Product', ['App\\Entity\\Other']);
    }

    public function testClassDoesNotExist(): void
    {
        $this->assertSame('App\\Does\\Not\\Exist', Validator::classDoesNotExist('App\\Does\\Not\\Exist'));
    }

    public function testClassDoesNotExistThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage(\sprintf('Class "%s" already exists.', InMemoryUser::class));
        Validator::classDoesNotExist(InMemoryUser::class);
    }

    public function testClassIsUserInterface(): void
    {
        $this->assertSame(InMemoryUser::class, Validator::classIsUserInterface(InMemoryUser::class));
    }

    public function testClassIsUserInterfaceThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        Validator::classIsUserInterface(\stdClass::class);
    }

    public function testClassIsBackedEnum(): void
    {
        $this->assertSame(ValidatorTestSuit::class, Validator::classIsBackedEnum(ValidatorTestSuit::class));
    }

    public function testClassIsBackedEnumThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessageMatches('/not a valid BackedEnum/');
        Validator::classIsBackedEnum(\stdClass::class);
    }
}
