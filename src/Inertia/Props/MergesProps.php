<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

trait MergesProps
{
    protected bool $merge = false;
    protected bool $deepMerge = false;
    /** @var list<string> */
    protected array $matchOn = [];
    protected bool $append = true;
    /** @var list<string> */
    protected array $appendsAtPaths = [];
    /** @var list<string> */
    protected array $prependsAtPaths = [];

    public function merge(): static
    {
        $this->merge = true;

        return $this;
    }

    public function deepMerge(): static
    {
        $this->deepMerge = true;

        return $this->merge();
    }

    /** @param string|list<string> $matchOn */
    public function matchOn(string|array $matchOn): static
    {
        $this->matchOn = is_array($matchOn) ? $matchOn : [$matchOn];

        return $this;
    }

    public function shouldMerge(): bool
    {
        return $this->merge;
    }

    public function shouldDeepMerge(): bool
    {
        return $this->deepMerge;
    }

    public function matchesOn(): array
    {
        return $this->matchOn;
    }

    public function appendsAtRoot(): bool
    {
        return $this->append && $this->mergesAtRoot();
    }

    public function prependsAtRoot(): bool
    {
        return !$this->append && $this->mergesAtRoot();
    }

    /**
     * Append (the default) — at the root when given nothing or `true`, or
     * inside the named path (`data`), optionally matching items on a key.
     *
     * @param bool|string|array<int|string, string> $path
     */
    public function append(bool|string|array $path = true, ?string $matchOn = null): static
    {
        if (is_bool($path)) {
            $this->append = $path;
        } elseif (is_string($path)) {
            $this->appendsAtPaths[] = $path;

            if ($matchOn !== null) {
                $this->matchOn = [...$this->matchOn, "{$path}.{$matchOn}"];
            }
        } else {
            foreach ($path as $key => $value) {
                is_int($key) ? $this->append($value) : $this->append($key, $value);
            }
        }

        return $this;
    }

    /** @param bool|string|array<int|string, string> $path */
    public function prepend(bool|string|array $path = true, ?string $matchOn = null): static
    {
        if (is_bool($path)) {
            $this->append = !$path;
        } elseif (is_string($path)) {
            $this->prependsAtPaths[] = $path;

            if ($matchOn !== null) {
                $this->matchOn = [...$this->matchOn, "{$path}.{$matchOn}"];
            }
        } else {
            foreach ($path as $key => $value) {
                is_int($key) ? $this->prepend($value) : $this->prepend($key, $value);
            }
        }

        return $this;
    }

    public function appendsAtPaths(): array
    {
        return $this->appendsAtPaths;
    }

    public function prependsAtPaths(): array
    {
        return $this->prependsAtPaths;
    }

    private function mergesAtRoot(): bool
    {
        return $this->appendsAtPaths === [] && $this->prependsAtPaths === [];
    }
}
