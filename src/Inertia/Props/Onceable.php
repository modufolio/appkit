<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop the client keeps across visits: listed under `onceProps`, and left
 * out of a later response when the client says it still holds it
 * (`X-Inertia-Except-Once-Props`), until it expires or is marked fresh.
 */
interface Onceable
{
    public function once(bool $value = true): static;

    public function shouldResolveOnce(): bool;

    public function shouldBeRefreshed(): bool;

    public function getKey(): ?string;

    public function as(\BackedEnum|\UnitEnum|string $key): static;

    public function until(\DateTimeInterface|\DateInterval|int $delay): static;

    /** Unix time in milliseconds, or null for "until the client forgets it". */
    public function expiresAt(): ?int;
}
