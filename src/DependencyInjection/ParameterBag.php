<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

class ParameterBag
{
    private array $parameters = [];
    private array $resolved = [];

    /** @var array<string, true> parameter keys currently being resolved, for cycle detection */
    private array $resolving = [];

    public function __construct(array $parameters = [])
    {
        $this->add($parameters);
    }

    public function clear(): void
    {
        $this->parameters = [];
        $this->resolved = [];
    }

    public function add(array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function all(): array
    {
        return $this->parameters;
    }

    public function get(string $name): mixed
    {
        $key = strtolower($name);
        if (!$this->has($key)) {
            throw new \InvalidArgumentException(sprintf('Parameter "%s" not found.', $name));
        }

        // Return a previously resolved value (the raw value must not be returned
        // here, or a resolved placeholder would surface as its literal "%name%").
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        if (!is_string($this->parameters[$key]) || !$this->isPlaceholder($this->parameters[$key])) {
            return $this->parameters[$key];
        }

        if (isset($this->resolving[$key])) {
            throw new \RuntimeException(sprintf('Circular reference detected while resolving parameter "%s" (path: %s).', $name, implode(' > ', array_keys($this->resolving))));
        }

        $this->resolving[$key] = true;
        try {
            return $this->resolved[$key] = $this->resolveValue($this->parameters[$key]);
        } finally {
            unset($this->resolving[$key]);
        }
    }

    public function set(string $name, mixed $value): void
    {
        $key = strtolower($name);
        $this->parameters[$key] = $value;
        unset($this->resolved[$key]);
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->parameters);
    }

    public function remove(string $name): void
    {
        $key = strtolower($name);
        unset($this->parameters[$key], $this->resolved[$key]);
    }

    public function resolve(): void
    {
        foreach ($this->parameters as $key => $value) {
            $this->resolved[$key] = $this->resolveValue($value);
        }
    }

    private function resolveValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $k => $v) {
                $resolved[$this->isPlaceholder($k) ? $this->resolveValue($k) : $k] = $this->resolveValue($v);
            }

            return $resolved;
        }

        if (!is_string($value) || !$this->isPlaceholder($value)) {
            return $value;
        }

        $placeholder = $this->getPlaceholderName($value);

        return $this->get($placeholder);
    }

    private function isPlaceholder(string $value): bool
    {
        return 1 === preg_match('/^%([^%]+)%$/', $value);
    }

    private function getPlaceholderName(string $value): string
    {
        return substr($value, 1, -1);
    }
}
