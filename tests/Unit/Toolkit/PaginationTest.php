<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use Modufolio\Appkit\Toolkit\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pagination::class)]
class PaginationTest extends TestCase
{
    protected function tearDown(): void
    {
        Pagination::$validate = true;
    }

    public function testDefaults()
    {
        $pagination = new Pagination();

        $this->assertSame(0, $pagination->page());
        $this->assertSame(0, $pagination->total());
        $this->assertSame(20, $pagination->limit());
    }

    public function testConstructWithProps()
    {
        $pagination = new Pagination([
            'page' => 2,
            'limit' => 10,
            'total' => 42,
        ]);

        $this->assertSame(2, $pagination->page());
        $this->assertSame(10, $pagination->limit());
        $this->assertSame(42, $pagination->total());
    }

    public function testConstructWithInvalidPage()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pagination page 5 does not exist, expected 1-2');

        new Pagination([
            'page' => 5,
            'limit' => 10,
            'total' => 12,
        ]);
    }

    public function testConstructWithoutValidation()
    {
        Pagination::$validate = false;

        // too high pages get limited to the last page
        $pagination = new Pagination([
            'page' => 5,
            'limit' => 10,
            'total' => 12,
        ]);
        $this->assertSame(2, $pagination->page());

        // too low pages get limited to the first page
        $pagination = new Pagination([
            'page' => 0,
            'limit' => 10,
            'total' => 12,
        ]);
        $this->assertSame(1, $pagination->page());
    }

    public function testConstructWithInvalidLimit()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid pagination limit: 0');

        new Pagination(['limit' => 0]);
    }

    public function testConstructWithInvalidTotal()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid total number of items: -1');

        new Pagination(['total' => -1]);
    }

    public function testConstructWithNonNumericPage()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid page number: foo');

        new Pagination(['page' => 'foo']);
    }

    public function testConstructWithNegativePage()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid page number: -1');

        new Pagination(['page' => '-1']);
    }

    public function testClone()
    {
        $pagination = new Pagination([
            'page' => 2,
            'limit' => 10,
            'total' => 42,
        ]);

        $clone = $pagination->clone(['page' => 3]);

        $this->assertSame(3, $clone->page());
        $this->assertSame(10, $clone->limit());
        $this->assertSame(42, $clone->total());
    }

    public function testForWithPaginationObject()
    {
        $collection = new Collection(['a', 'b', 'c']);
        $pagination = new Pagination(['total' => 3]);

        $this->assertSame($pagination, Pagination::for($collection, $pagination));
    }

    public function testForWithOptionsArray()
    {
        $collection = new Collection(['a', 'b', 'c']);
        $pagination = Pagination::for($collection, [
            'limit' => 2,
            'page' => 2,
        ]);

        $this->assertSame(2, $pagination->limit());
        $this->assertSame(2, $pagination->page());
        $this->assertSame(3, $pagination->total());
    }

    public function testForWithLimit()
    {
        $collection = new Collection(['a', 'b', 'c']);
        $pagination = Pagination::for($collection, 2);

        $this->assertSame(2, $pagination->limit());
        $this->assertSame(1, $pagination->page());
        $this->assertSame(3, $pagination->total());
    }

    public function testForWithLimitAndPage()
    {
        $collection = new Collection(['a', 'b', 'c']);
        $pagination = Pagination::for($collection, 2, 2);

        $this->assertSame(2, $pagination->limit());
        $this->assertSame(2, $pagination->page());
    }

    public function testForWithLimitAndOptions()
    {
        $collection = new Collection(['a', 'b', 'c']);
        $pagination = Pagination::for($collection, 2, ['page' => 2]);

        $this->assertSame(2, $pagination->limit());
        $this->assertSame(2, $pagination->page());
    }

    public function testStartAndEnd()
    {
        $pagination = new Pagination([
            'page' => 2,
            'limit' => 10,
            'total' => 42,
        ]);

        $this->assertSame(11, $pagination->start());
        $this->assertSame(20, $pagination->end());

        // last page ends at the total
        $pagination = new Pagination([
            'page' => 5,
            'limit' => 10,
            'total' => 42,
        ]);

        $this->assertSame(41, $pagination->start());
        $this->assertSame(42, $pagination->end());
    }

    public function testPages()
    {
        $pagination = new Pagination();
        $this->assertSame(0, $pagination->pages());

        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertSame(5, $pagination->pages());
    }

    public function testFirstAndLastPage()
    {
        $pagination = new Pagination();
        $this->assertSame(0, $pagination->firstPage());
        $this->assertSame(0, $pagination->lastPage());

        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertSame(1, $pagination->firstPage());
        $this->assertSame(5, $pagination->lastPage());
    }

    public function testOffset()
    {
        $pagination = new Pagination([
            'page' => 3,
            'limit' => 10,
            'total' => 42,
        ]);

        $this->assertSame(20, $pagination->offset());
    }

    public function testHasPage()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 42]);

        $this->assertFalse($pagination->hasPage(0));
        $this->assertFalse($pagination->hasPage(6));
        $this->assertTrue($pagination->hasPage(1));
        $this->assertTrue($pagination->hasPage(5));
    }

    public function testHasPages()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 5]);
        $this->assertFalse($pagination->hasPages());

        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertTrue($pagination->hasPages());
    }

    public function testPrevPage()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertFalse($pagination->hasPrevPage());
        $this->assertNull($pagination->prevPage());

        $pagination = new Pagination(['page' => 3, 'limit' => 10, 'total' => 42]);
        $this->assertTrue($pagination->hasPrevPage());
        $this->assertSame(2, $pagination->prevPage());
    }

    public function testNextPage()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertTrue($pagination->hasNextPage());
        $this->assertSame(2, $pagination->nextPage());

        $pagination = new Pagination(['page' => 5, 'limit' => 10, 'total' => 42]);
        $this->assertFalse($pagination->hasNextPage());
        $this->assertNull($pagination->nextPage());
    }

    public function testIsFirstAndLastPage()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 42]);
        $this->assertTrue($pagination->isFirstPage());
        $this->assertFalse($pagination->isLastPage());

        $pagination = new Pagination(['page' => 5, 'limit' => 10, 'total' => 42]);
        $this->assertFalse($pagination->isFirstPage());
        $this->assertTrue($pagination->isLastPage());
    }

    public function testRangeWithFewPages()
    {
        $pagination = new Pagination(['limit' => 10, 'total' => 30]);

        $this->assertSame([1, 2, 3], $pagination->range());
    }

    public function testRange()
    {
        // middle of the range
        $pagination = new Pagination(['page' => 5, 'limit' => 10, 'total' => 100]);
        $this->assertSame([3, 4, 5, 6, 7], $pagination->range());

        // start of the range
        $pagination = new Pagination(['page' => 1, 'limit' => 10, 'total' => 100]);
        $this->assertSame([1, 2, 3, 4, 5], $pagination->range());

        // end of the range
        $pagination = new Pagination(['page' => 10, 'limit' => 10, 'total' => 100]);
        $this->assertSame([6, 7, 8, 9, 10], $pagination->range());

        // even range
        $pagination = new Pagination(['page' => 5, 'limit' => 10, 'total' => 100]);
        $this->assertSame([4, 5, 6, 7], $pagination->range(4));
    }

    public function testRangeStartAndEnd()
    {
        $pagination = new Pagination(['page' => 5, 'limit' => 10, 'total' => 100]);

        $this->assertSame(3, $pagination->rangeStart());
        $this->assertSame(7, $pagination->rangeEnd());
    }

    public function testToArray()
    {
        $pagination = new Pagination([
            'page' => 2,
            'limit' => 10,
            'total' => 42,
        ]);

        $this->assertSame([
            'page' => 2,
            'firstPage' => 1,
            'lastPage' => 5,
            'pages' => 5,
            'offset' => 10,
            'limit' => 10,
            'total' => 42,
            'start' => 11,
            'end' => 20,
        ], $pagination->toArray());
    }
}
