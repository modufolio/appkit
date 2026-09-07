<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Flash;

final class ArrayFlashStore implements FlashStoreInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function put(array $data): void
    {
        $this->data = [...$this->data, ...$data];
    }

    public function peek(): array
    {
        return $this->data;
    }

    public function pull(): array
    {
        $data = $this->data;
        $this->data = [];

        return $data;
    }
}
