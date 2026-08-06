<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Database;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Modufolio\Appkit\Doctrine\DoctrineOrmPagination;
use Modufolio\Appkit\Tests\App\Entity\Account;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DoctrineOrmPagination::class)]
class DoctrineOrmPaginationTest extends AppTestCase
{
    protected function seedAccounts(int $count): void
    {
        $this->refreshDatabase();

        $em = $this->app()->entityManager();
        for ($i = 1; $i <= $count; ++$i) {
            $account = new Account();
            $account->setName('Account '.$i);
            $em->persist($account);
        }
        $em->flush();
        $em->clear();
    }

    protected function paginate(int $page = 1, int $limit = 10): DoctrineOrmPagination
    {
        $query = $this->app()->entityManager()
            ->createQuery('SELECT a FROM '.Account::class.' a ORDER BY a.id ASC');

        return (new DoctrineOrmPagination())->paginate($query, $page, $limit);
    }

    public function testPaginateFirstPage(): void
    {
        $this->seedAccounts(25);

        $pagination = $this->paginate(1, 10);

        $this->assertSame(1, $pagination->page());
        $this->assertSame(25, $pagination->total());
        $this->assertSame(10, $pagination->limit());
        $this->assertSame(3, $pagination->pages());
        $this->assertSame(3, $pagination->lastPage());
        $this->assertSame(1, $pagination->firstPage());
        $this->assertSame(1, $pagination->start());
        $this->assertSame(10, $pagination->end());
        $this->assertSame(0, $pagination->offset());
        $this->assertCount(10, $pagination->getResults());
        $this->assertInstanceOf(Paginator::class, $pagination->items());
        $this->assertTrue($pagination->hasPages());
        $this->assertTrue($pagination->hasPagination());
        $this->assertTrue($pagination->isFirstPage());
        $this->assertFalse($pagination->isLastPage());
        $this->assertFalse($pagination->hasPrevPage());
        $this->assertNull($pagination->prevPage());
        $this->assertTrue($pagination->hasNextPage());
        $this->assertSame(2, $pagination->nextPage());
        $this->assertTrue($pagination->hasPage(3));
        $this->assertFalse($pagination->hasPage(4));
        $this->assertFalse($pagination->hasPage(0));
        $this->assertSame([1, 2, 3], $pagination->range());
    }

    public function testPaginateMiddlePage(): void
    {
        $this->seedAccounts(25);

        $pagination = $this->paginate(2, 10);

        $this->assertSame(2, $pagination->page());
        $this->assertSame(11, $pagination->start());
        $this->assertSame(20, $pagination->end());
        $this->assertSame(10, $pagination->offset());
        $this->assertTrue($pagination->hasPrevPage());
        $this->assertSame(1, $pagination->prevPage());
        $this->assertSame(3, $pagination->nextPage());
        $this->assertFalse($pagination->isFirstPage());
        $this->assertFalse($pagination->isLastPage());
    }

    public function testPaginateLastPage(): void
    {
        $this->seedAccounts(25);

        $pagination = $this->paginate(3, 10);

        $this->assertSame(21, $pagination->start());
        $this->assertSame(25, $pagination->end());
        $this->assertCount(5, $pagination->getResults());
        $this->assertTrue($pagination->isLastPage());
        $this->assertFalse($pagination->hasNextPage());
        $this->assertNull($pagination->nextPage());
    }

    public function testPaginateEmptyResult(): void
    {
        $this->seedAccounts(0);

        $pagination = $this->paginate(1, 10);

        $this->assertSame(0, $pagination->total());
        $this->assertSame(0, $pagination->start());
        $this->assertSame(0, $pagination->end());
        $this->assertSame(0, $pagination->firstPage());
        $this->assertSame(1, $pagination->lastPage());
        $this->assertSame([], $pagination->range());
        $this->assertSame([], $pagination->getResults());
        $this->assertFalse($pagination->hasPages());
        $this->assertFalse($pagination->hasPagination());
        $this->assertFalse($pagination->isFirstPage());
    }

    public function testPaginateNormalizesPageAndLimit(): void
    {
        $this->seedAccounts(5);

        $pagination = $this->paginate(-3, -1);

        $this->assertSame(1, $pagination->page());
        $this->assertSame(1, $pagination->limit());
        $this->assertSame(5, $pagination->pages());
    }

    public function testRangeIsClampedNearTheEnd(): void
    {
        $this->seedAccounts(60);

        $pagination = $this->paginate(6, 10);

        // 6 pages in total, so a 5-wide window around page 6 shifts back
        $this->assertSame([2, 3, 4, 5, 6], $pagination->range());
    }

    public function testRangeAtTheStart(): void
    {
        $this->seedAccounts(60);

        $pagination = $this->paginate(1, 10);

        $this->assertSame([1, 2, 3, 4, 5], $pagination->range());
    }
}
