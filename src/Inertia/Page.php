<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The Inertia page object for one request: the component, the props that
 * travel on this response, the URL, the asset version, the flash data, and
 * the metadata the client reads to know what to fetch next, what to merge,
 * what to keep and what to scroll — see {@see PropsResolver} for the rules.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class Page
{
    /** @var array<string, mixed> */
    private readonly array $props;

    /** @var array<string, mixed> */
    private readonly array $metadata;

    private readonly bool $partial;

    /**
     * @param array<string, mixed> $props  the page's own props: values, closures, Prop instances
     * @param array<string, mixed> $shared merged underneath; their top-level keys are listed as `sharedProps`
     * @param array<string, mixed> $flash  what the server flashed for this response
     *
     * @internal the renderer builds pages; a host receives one in
     *           {@see RootViewInterface::render()} and only reads it
     */
    public function __construct(
        private readonly string $component,
        array $props,
        private readonly string $url,
        private readonly string $version,
        ServerRequestInterface $request,
        private readonly bool $encryptHistory = false,
        private readonly bool $clearHistory = false,
        array $shared = [],
        private readonly array $flash = [],
        private readonly bool $preserveFragment = false,
    ) {
        $resolver = new PropsResolver($request, $component);
        [$resolved, $metadata] = $resolver->resolve($shared, $props);

        $this->props = self::stringKeys($resolved);
        $this->metadata = $metadata;
        $this->partial = $resolver->isPartial();
    }

    public function component(): string
    {
        return $this->component;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return $this->props;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function flash(): array
    {
        return $this->flash;
    }

    /** Whether this page answers a partial reload of the same component. */
    public function isPartial(): bool
    {
        return $this->partial;
    }

    /**
     * The page object as the client reads it. Optional keys appear only when
     * they carry something.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $page = [
            'component' => $this->component,
            'props'     => $this->props,
            'url'       => $this->url,
            'version'   => $this->version,
            ...$this->metadata,
        ];

        if ($this->encryptHistory) {
            $page['encryptHistory'] = true;
        }

        if ($this->clearHistory) {
            $page['clearHistory'] = true;
        }

        if ($this->preserveFragment) {
            $page['preserveFragment'] = true;
        }

        if ($this->flash !== []) {
            $page['flash'] = $this->flash;
        }

        return $page;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
    }

    /**
     * @param  array<array-key, mixed> $props
     * @return array<string, mixed>
     */
    private static function stringKeys(array $props): array
    {
        $result = [];

        foreach ($props as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
