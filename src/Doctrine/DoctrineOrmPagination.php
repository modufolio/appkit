<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;

class DoctrineOrmPagination
{
    protected int $page = 1;
    protected int $total = 0;
    protected int $limit = 20;
    private int $lastPage;
    private Paginator $items;
    protected Query $query;

    public function paginate(Query $query, int $page = 1, int $limit = 10): self
    {
        $this->page = max(1, $page);
        $this->limit = max(1, $limit);
        $this->query = $query;

        $paginator = new Paginator($query);

        $paginator
            ->getQuery()
            ->setFirstResult($this->limit * ($this->page - 1))
            ->setMaxResults($this->limit);

        $this->total = $paginator->count();
        $this->lastPage = max(1, (int)ceil($this->total / $this->limit));
        $this->items = $paginator;

        return $this;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function getResults(): array
    {
        return iterator_to_array($this->items);
    }

    public function start(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->limit + 1;
    }

    public function end(): int
    {
        return $this->total === 0 ? 0 : min($this->total, $this->start() + $this->limit - 1);
    }

    public function pages(): int
    {
        return $this->lastPage;
    }

    public function firstPage(): int
    {
        return $this->total === 0 ? 0 : 1;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    public function offset(): int
    {
        return abs($this->page - 1) * $this->limit;
    }

    public function hasPage(int $page): bool
    {
        return $page > 0 && $page <= $this->lastPage;
    }

    public function hasPages(): bool
    {
        return $this->total > $this->limit;
    }

    public function hasPrevPage(): bool
    {
        return $this->page > 1;
    }

    public function prevPage(): ?int
    {
        return $this->hasPrevPage() ? $this->page - 1 : null;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->lastPage;
    }

    public function nextPage(): ?int
    {
        return $this->hasNextPage() ? $this->page + 1 : null;
    }

    public function isFirstPage(): bool
    {
        return $this->page === $this->firstPage();
    }

    public function isLastPage(): bool
    {
        return $this->page === $this->lastPage;
    }

    public function range(int $range = 5): array
    {
        if ($this->total === 0) {
            return [];
        }

        $start = max(1, $this->page - floor($range / 2));
        $end = min($this->lastPage, $start + $range - 1);

        if ($end - $start + 1 < $range) {
            $start = max(1, $end - $range + 1);
        }

        return range($start, $end);
    }

    public function items(): Paginator
    {
        return $this->items;
    }

    public function hasPagination(): bool
    {
        return $this->total > 0 && $this->total > $this->limit;
    }
}
