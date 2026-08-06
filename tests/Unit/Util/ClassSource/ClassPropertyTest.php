<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Util\ClassSource;

use Doctrine\ORM\Mapping\FieldMapping;
use Modufolio\Appkit\Exception\RuntimeCommandException;
use Modufolio\Appkit\Util\ClassSource\Model\ClassProperty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassProperty::class)]
class ClassPropertyTest extends TestCase
{
    public function testGetAttributesWithDefaults(): void
    {
        $property = new ClassProperty('title', 'string');

        $this->assertSame(['type' => 'string'], $property->getAttributes());
    }

    public function testGetAttributesWithoutTypeHint(): void
    {
        $property = new ClassProperty('title', 'string', needsTypeHint: false);

        $this->assertSame([], $property->getAttributes());
    }

    public function testGetAttributesWithAllOptions(): void
    {
        $property = new ClassProperty(
            propertyName: 'price',
            type: 'decimal',
            length: 32,
            id: true,
            nullable: false,
            options: ['unsigned' => true],
            precision: 10,
            scale: 2,
            unique: true,
            enumType: 'App\\Enum\\Status',
        );

        $this->assertSame([
            'type' => 'decimal',
            'options' => ['unsigned' => true],
            'unique' => true,
            'enumType' => 'App\\Enum\\Status',
            'length' => 32,
            'id' => true,
            'nullable' => false,
            'precision' => 10,
            'scale' => 2,
        ], $property->getAttributes());
    }

    public function testCreateFromFieldMapping(): void
    {
        $mapping = new FieldMapping('string', 'title', 'title');
        $mapping->length = 100;
        $mapping->nullable = true;
        $mapping->unique = true;

        $property = ClassProperty::createFromObject($mapping);

        $this->assertSame('title', $property->propertyName);
        $this->assertSame('string', $property->type);
        $this->assertSame(100, $property->length);
        $this->assertFalse($property->id);
        $this->assertTrue($property->nullable);
        $this->assertTrue($property->unique);
        $this->assertNull($property->enumType);
    }

    public function testCreateFromArray(): void
    {
        $property = ClassProperty::createFromObject([
            'fieldName' => 'amount',
            'type' => 'decimal',
            'precision' => 8,
            'scale' => 2,
            'nullable' => true,
            'comments' => ['a comment'],
        ]);

        $this->assertSame('amount', $property->propertyName);
        $this->assertSame('decimal', $property->type);
        $this->assertSame(8, $property->precision);
        $this->assertSame(2, $property->scale);
        $this->assertTrue($property->nullable);
        $this->assertSame(['a comment'], $property->comments);
        $this->assertFalse($property->unique);
    }

    public function testCreateFromArrayWithoutRequiredKeys(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('Cannot create property model - "fieldName" & "type" are required.');

        ClassProperty::createFromObject(['fieldName' => 'title']);
    }
}
