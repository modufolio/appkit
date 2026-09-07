<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

trait ResolvesOnce
{
    protected bool $once = false;
    protected bool $refresh = false;
    protected ?int $ttl = null;
    protected ?string $key = null;

    public function once(bool $value = true, ?string $as = null, \DateTimeInterface|\DateInterval|int|null $until = null): static
    {
        $this->once = $value;

        if ($as !== null) {
            $this->as($as);
        }

        if ($until !== null) {
            $this->until($until);
        }

        return $this;
    }

    public function shouldResolveOnce(): bool
    {
        return $this->once;
    }

    public function shouldBeRefreshed(): bool
    {
        return $this->refresh;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function as(\BackedEnum|\UnitEnum|string $key): static
    {
        $this->key = match (true) {
            $key instanceof \BackedEnum => (string) $key->value,
            $key instanceof \UnitEnum => $key->name,
            default => $key,
        };

        return $this;
    }

    /** Send it again even though the client says it holds it. */
    public function fresh(bool $value = true): static
    {
        $this->refresh = $value;

        return $this;
    }

    public function until(\DateTimeInterface|\DateInterval|int $delay): static
    {
        $this->ttl = match (true) {
            $delay instanceof \DateTimeInterface => max(0, $delay->getTimestamp() - time()),
            $delay instanceof \DateInterval => max(0, (new \DateTimeImmutable())->add($delay)->getTimestamp() - time()),
            default => max(0, $delay),
        };

        return $this;
    }

    public function expiresAt(): ?int
    {
        return $this->ttl === null ? null : (time() + $this->ttl) * 1000;
    }
}
