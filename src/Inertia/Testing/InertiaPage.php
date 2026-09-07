<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Testing;

use Psr\Http\Message\ResponseInterface;

/**
 * The page object read back out of a response, for a test: from the JSON an
 * XHR visit receives, or from the `data-page` attribute of a rendered
 * document. Query it, then assert with the framework of your choice.
 *
 * ```php
 * $page = InertiaPage::fromResponse($response);
 * self::assertSame('Users/Edit', $page->component());
 * self::assertSame('Ada', $page->prop('user.name'));
 * ```
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class InertiaPage
{
    /** @param array<string, mixed> $page */
    private function __construct(private readonly array $page)
    {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $body = (string) $response->getBody();
        $type = $response->getHeaderLine('Content-Type');

        if (str_contains($type, 'application/json')) {
            return self::fromJson($body);
        }

        // The script element the 3.x client reads, or the data-page attribute
        // earlier clients read.
        if (preg_match('#<script[^>]*data-page="[^"]*"[^>]*>(.*?)</script>#s', $body, $match) === 1) {
            return self::fromJson($match[1]);
        }

        if (preg_match('/data-page="([^"]*)"/', $body, $match) === 1) {
            return self::fromJson(html_entity_decode($match[1], ENT_QUOTES));
        }

        throw new \LogicException('The response is neither an Inertia JSON page nor a document carrying one.');
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded['component'])) {
            throw new \LogicException('Not an Inertia page object: no "component" key.');
        }

        return new self(self::stringKeys($decoded));
    }

    public function component(): string
    {
        return $this->string('component');
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return $this->map('props');
    }

    /** A prop by dot path: `user.name`. Null when absent. */
    public function prop(string $path): mixed
    {
        $value = $this->props();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $prop): bool
    {
        return array_key_exists($prop, $this->props());
    }

    public function url(): string
    {
        return $this->string('url');
    }

    public function version(): string
    {
        return $this->string('version');
    }

    /** @return array<string, mixed> */
    public function flash(): array
    {
        return $this->map('flash');
    }

    /** @return array<string, list<string>> */
    public function deferredProps(): array
    {
        $result = [];

        foreach ($this->map('deferredProps') as $group => $names) {
            $result[$group] = self::strings($names);
        }

        return $result;
    }

    /** @return list<string> */
    public function mergeProps(): array
    {
        return self::strings($this->page['mergeProps'] ?? []);
    }

    /** @return list<string> */
    public function prependProps(): array
    {
        return self::strings($this->page['prependProps'] ?? []);
    }

    /** @return list<string> */
    public function deepMergeProps(): array
    {
        return self::strings($this->page['deepMergeProps'] ?? []);
    }

    /** @return list<string> */
    public function matchPropsOn(): array
    {
        return self::strings($this->page['matchPropsOn'] ?? []);
    }

    /** @return list<string> */
    public function sharedProps(): array
    {
        return self::strings($this->page['sharedProps'] ?? []);
    }

    /** @return list<string> */
    public function rescuedProps(): array
    {
        return self::strings($this->page['rescuedProps'] ?? []);
    }

    /** @return array<string, array<string, mixed>> */
    public function scrollProps(): array
    {
        $result = [];

        foreach ($this->map('scrollProps') as $prop => $metadata) {
            $result[$prop] = is_array($metadata) ? self::stringKeys($metadata) : [];
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    public function onceProps(): array
    {
        $result = [];

        foreach ($this->map('onceProps') as $key => $entry) {
            $result[$key] = is_array($entry) ? self::stringKeys($entry) : [];
        }

        return $result;
    }

    public function encryptsHistory(): bool
    {
        return ($this->page['encryptHistory'] ?? false) === true;
    }

    public function clearsHistory(): bool
    {
        return ($this->page['clearHistory'] ?? false) === true;
    }

    public function preservesFragment(): bool
    {
        return ($this->page['preserveFragment'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->page;
    }

    private function string(string $key): string
    {
        $value = $this->page[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<string, mixed> */
    private function map(string $key): array
    {
        $value = $this->page[$key] ?? [];

        return is_array($value) ? self::stringKeys($value) : [];
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

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
