<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

/**
 * Minimal in-memory session for testing.
 *
 * Implements FlashBagAwareSessionInterface so FormLoginAuthenticator
 * can store flash messages without a real session backend.
 */
class TestSession implements FlashBagAwareSessionInterface
{
    private FlashBagInterface $flashBag;
    private array $attributes = [];
    private bool $started = false;
    private string $id;

    public function __construct(?FlashBagInterface $flashBag = null)
    {
        $this->flashBag = $flashBag ?? new FlashBag();
        $this->id = bin2hex(random_bytes(16));
    }

    public function getFlashBag(): FlashBagInterface
    {
        return $this->flashBag;
    }

    public function start(): bool
    {
        $this->started = true;
        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return 'PHPSESSID';
    }

    public function setName(string $name): void
    {
    }

    public function invalidate(?int $lifetime = null): bool
    {
        $this->attributes = [];
        return true;
    }

    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        return true;
    }

    public function save(): void
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function all(): array
    {
        return $this->attributes;
    }

    public function replace(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function remove(string $name): mixed
    {
        $value = $this->attributes[$name] ?? null;
        unset($this->attributes[$name]);
        return $value;
    }

    public function clear(): void
    {
        $this->attributes = [];
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function registerBag(SessionBagInterface $bag): void
    {
    }

    public function getBag(string $name): SessionBagInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getMetadataBag(): MetadataBag
    {
        throw new \RuntimeException('Not implemented');
    }
}
