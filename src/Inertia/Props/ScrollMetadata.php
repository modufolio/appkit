<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * Scroll metadata by hand: the query parameter that names the page, and the
 * previous, next and current page (numbers or cursors).
 *
 * `fromPage()` derives it from a page number, a per-page size and a total,
 * which is what a JSON:API-style paginator already knows.
 */
final class ScrollMetadata implements ProvidesScrollMetadata
{
    public function __construct(
        private readonly string $pageName,
        private readonly int|string|null $previousPage = null,
        private readonly int|string|null $nextPage = null,
        private readonly int|string|null $currentPage = null,
    ) {
    }

    public static function fromPage(int $currentPage, int $perPage, int $total, string $pageName = 'page'): self
    {
        $lastPage = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;

        return new self(
            $pageName,
            $currentPage > 1 ? $currentPage - 1 : null,
            $currentPage < $lastPage ? $currentPage + 1 : null,
            $currentPage,
        );
    }

    public function getPageName(): string
    {
        return $this->pageName;
    }

    public function getPreviousPage(): int|string|null
    {
        return $this->previousPage;
    }

    public function getNextPage(): int|string|null
    {
        return $this->nextPage;
    }

    public function getCurrentPage(): int|string|null
    {
        return $this->currentPage;
    }

    /** @return array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null} */
    public function toArray(): array
    {
        return [
            'pageName'     => $this->pageName,
            'previousPage' => $this->previousPage,
            'nextPage'     => $this->nextPage,
            'currentPage'  => $this->currentPage,
        ];
    }
}
