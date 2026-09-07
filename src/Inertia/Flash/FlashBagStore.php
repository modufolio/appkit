<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Flash;

use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

/**
 * Flash data in a Symfony flash bag, under one key, so it survives the
 * redirect that follows a write and is gone after the page that shows it.
 * What appkit's session offers; the Inertia module wires it when the bag is
 * a service.
 */
final class FlashBagStore implements FlashStoreInterface
{
    public const KEY = 'inertia.flash_data';

    public function __construct(private readonly FlashBagInterface $flashBag)
    {
    }

    public function put(array $data): void
    {
        $this->flashBag->set(self::KEY, [[...$this->peek(), ...$data]]);
    }

    public function peek(): array
    {
        $entries = $this->flashBag->peek(self::KEY);
        $data = $entries[0] ?? [];

        return is_array($data) ? self::stringKeys($data) : [];
    }

    public function pull(): array
    {
        $entries = $this->flashBag->get(self::KEY);
        $data = $entries[0] ?? [];

        return is_array($data) ? self::stringKeys($data) : [];
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
