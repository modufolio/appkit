<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionFilterTest extends TestCase
{
    protected function _collection(): Collection
    {
        return new Collection([
            'one' => ['name' => 'Bastian', 'color' => 'red'],
            'two' => ['name' => 'Nico', 'color' => 'green'],
            'three' => ['name' => 'Lukas', 'color' => 'red'],
        ]);
    }

    public function testFilterWithCallback()
    {
        $collection = $this->_collection();
        $result = $collection->filter(fn ($item) => 'red' === $item['color']);

        $this->assertSame(['one', 'three'], $result->keys());
        $this->assertCount(3, $collection);
    }

    public function testFilterWithArrayOfFilters()
    {
        $result = $this->_collection()->filter([
            ['color', 'red'],
            ['name', 'Lukas'],
        ]);

        $this->assertSame(['three'], $result->keys());
    }

    public function testFilterWithDefaultOperator()
    {
        $result = $this->_collection()->filter('color', 'green');

        $this->assertSame(['two'], $result->keys());
    }

    public function testFilterWithOperator()
    {
        $result = $this->_collection()->filter('color', '==', 'red');

        $this->assertSame(['one', 'three'], $result->keys());
    }

    public function testFilterWithStringableTest()
    {
        $test = new class {
            public function __toString(): string
            {
                return 'red';
            }
        };

        $result = $this->_collection()->filter('color', $test);

        $this->assertSame(['one', 'three'], $result->keys());
    }

    public function testFilterWithSplit()
    {
        $collection = new Collection([
            'one' => ['tags' => 'a, b'],
            'two' => ['tags' => 'b, c'],
            'three' => ['tags' => 'c, d'],
        ]);

        $result = $collection->filter('tags', '==', 'b', true);

        $this->assertSame(['one', 'two'], $result->keys());
    }

    public function testFilterWithValidatorArray()
    {
        Collection::$filters['contains?'] = [
            'validator' => fn ($value, $test) => str_contains($value, $test),
        ];

        try {
            $result = $this->_collection()->filter('name', 'contains?', 'as');
            $this->assertSame(['one', 'three'], $result->keys());
        } finally {
            unset(Collection::$filters['contains?']);
        }
    }

    public function testFilterWithValidatorArrayAndSplit()
    {
        Collection::$filters['all?'] = [
            'validator' => fn ($value, $test) => str_contains($value, $test),
        ];
        Collection::$filters['any?'] = [
            'validator' => fn ($value, $test) => str_contains($value, $test),
            'strict' => false,
        ];

        $collection = new Collection([
            'one' => ['tags' => 'ab, ba'],
            'two' => ['tags' => 'ab, cd'],
            'three' => ['tags' => 'cd, ef'],
        ]);

        try {
            // strict: all split values need to match
            $result = $collection->filter('tags', 'all?', 'a', true);
            $this->assertSame(['one'], $result->keys());

            // non-strict: a single matching split value is enough
            $result = $collection->filter('tags', 'any?', 'a', true);
            $this->assertSame(['one', 'two'], $result->keys());
        } finally {
            unset(Collection::$filters['all?'], Collection::$filters['any?']);
        }
    }

    public function testFilterMatchesNone()
    {
        $collection = new class extends Collection {
            public function matchesNone(callable $validator, array $values, $test): bool
            {
                return $this->filterMatchesNone($validator, $values, $test);
            }
        };

        $validator = fn ($value, $test) => $value === $test;

        $this->assertTrue($collection->matchesNone($validator, ['a', 'b'], 'c'));
        $this->assertFalse($collection->matchesNone($validator, ['a', 'b'], 'a'));
    }

    public function testFilterBy()
    {
        $result = $this->_collection()->filterBy('color', 'red');

        $this->assertSame(['one', 'three'], $result->keys());
    }

    public function testGroup()
    {
        $groups = $this->_collection()->group('color');

        $this->assertCount(2, $groups);
        $this->assertSame(['one', 'three'], $groups->get('red')->keys());
        $this->assertSame(['two'], $groups->get('green')->keys());
    }

    public function testGroupCaseSensitivity()
    {
        $collection = new Collection([
            'one' => ['color' => 'Red'],
            'two' => ['color' => 'red'],
        ]);

        // case insensitive by default
        $this->assertCount(1, $collection->group('color'));

        // case sensitive group names are kept as they are
        $collection = new Collection([
            'one' => ['color' => 'Red'],
            'two' => ['color' => 'blue'],
        ]);

        $groups = $collection->group('color', false);
        $this->assertCount(2, $groups);
        $this->assertSame(['one'], $groups->get('Red')->keys());
    }

    public function testGroupWithCallback()
    {
        $groups = $this->_collection()->group(fn ($item) => $item['color']);

        $this->assertSame(['one', 'three'], $groups->get('red')->keys());
    }

    public function testGroupWithStringableValue()
    {
        $value = new class {
            public function __toString(): string
            {
                return 'group';
            }
        };

        $groups = $this->_collection()->group(fn ($item) => $value);

        $this->assertSame(['one', 'two', 'three'], $groups->get('group')->keys());
    }

    public function testGroupWithInvalidValue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid grouping value for key: one');

        $this->_collection()->group(fn ($item) => null);
    }

    public function testGroupWithArrayValue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You cannot group by arrays or objects');

        $this->_collection()->group(fn ($item) => ['a']);
    }

    public function testGroupWithObjectValue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You cannot group by arrays or objects');

        $this->_collection()->group(fn ($item) => new \stdClass());
    }

    public function testGroupWithInvalidField()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Can only group by string values or by providing a callback function');

        $this->_collection()->group(1);
    }

    public function testGroupBy()
    {
        $groups = $this->_collection()->groupBy('color');

        $this->assertSame(['one', 'three'], $groups->get('red')->keys());
    }

    public function testQuery()
    {
        $collection = new Collection([
            'one' => ['name' => 'Bastian', 'color' => 'red'],
            'two' => ['name' => 'Nico', 'color' => 'green'],
            'three' => ['name' => 'Lukas', 'color' => 'red'],
            'four' => ['name' => 'Sonja', 'color' => 'red'],
        ]);

        $result = $collection->query([
            'not' => ['four'],
            'filterBy' => [
                [
                    'field' => 'color',
                    'operator' => '==',
                    'value' => 'red',
                ],
                [
                    'field' => 'name',
                ],
            ],
            'offset' => 0,
            'limit' => 10,
            'sortBy' => 'name desc',
        ]);

        $this->assertSame(['three', 'one'], $result->keys());
    }

    public function testQueryWithSortArray()
    {
        $result = $this->_collection()->query([
            'sort' => ['name', 'asc'],
        ]);

        $this->assertSame(['one', 'three', 'two'], $result->keys());
    }

    public function testQueryWithSortComma()
    {
        $result = $this->_collection()->query([
            'sortBy' => 'color asc, name desc',
        ]);

        $this->assertCount(3, $result);
        $this->assertEqualsCanonicalizing(['one', 'two', 'three'], $result->keys());
    }

    public function testQueryWithPagination()
    {
        $result = $this->_collection()->query([
            'paginate' => 2,
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(2, $result->pagination()->limit());
    }

    public function testWhen()
    {
        $collection = $this->_collection();

        // truthy condition executes the callback
        $result = $collection->when(true, fn () => $collection->filter('color', 'red'));
        $this->assertSame(['one', 'three'], $result->keys());

        // falsy condition without fallback returns the collection
        $result = $collection->when(false, fn () => $collection->filter('color', 'red'));
        $this->assertSame($collection, $result);

        // falsy condition executes the fallback
        $result = $collection->when(
            false,
            fn () => $collection->filter('color', 'red'),
            fn () => $collection->filter('color', 'green')
        );
        $this->assertSame(['two'], $result->keys());
    }

    public function testEqualsFilterWithSplit(): void
    {
        $collection = new Collection([
            ['tags' => 'a, b'],
            ['tags' => 'c'],
        ]);

        $filtered = $collection->filterBy('tags', 'a', ', ');

        $this->assertCount(1, $filtered);
    }
}
