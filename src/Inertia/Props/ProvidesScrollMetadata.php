<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/** What the client's infinite scroll needs to know about a page of a list. */
interface ProvidesScrollMetadata
{
    public function getPageName(): string;

    public function getPreviousPage(): int|string|null;

    public function getNextPage(): int|string|null;

    public function getCurrentPage(): int|string|null;
}
