<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Inertia\Props\AlwaysProp;
use Modufolio\Appkit\Inertia\Props\Deferrable;
use Modufolio\Appkit\Inertia\Props\IgnoreFirstLoad;
use Modufolio\Appkit\Inertia\Props\Mergeable;
use Modufolio\Appkit\Inertia\Props\Onceable;
use Modufolio\Appkit\Inertia\Props\Rescuable;
use Modufolio\Appkit\Inertia\Props\ScrollProp;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns declared props into the props one response sends, and the metadata
 * the client reads alongside them — the Inertia 3 rules, as the reference
 * adapter applies them:
 *
 *   - a partial reload (`X-Inertia-Partial-Component` naming this component)
 *     narrows to `only` minus `except` by dot path, at any depth; an
 *     {@see AlwaysProp} always travels; children of a value that was
 *     computed from a closure travel with it;
 *   - a full load leaves out {@see IgnoreFirstLoad} props (optional,
 *     deferred) and deferred scroll props, but still lists them under
 *     `deferredProps`, and their merge and once metadata;
 *   - a once prop the client says it holds (`X-Inertia-Except-Once-Props`)
 *     is left out, unless expired on the client's side or marked fresh;
 *   - closures, at any depth, are computed only for props that travel;
 *   - `key.path` props are unpacked into their parents first;
 *   - a {@see Rescuable} prop that throws yields null and is listed under
 *     `rescuedProps` instead of failing the page;
 *   - `errors` is wrapped under the bag `X-Inertia-Error-Bag` names.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class PropsResolver
{
    private readonly bool $partial;
    private readonly bool $inertia;
    /** @var list<string>|null */
    private readonly ?array $only;
    /** @var list<string>|null */
    private readonly ?array $except;
    /** @var list<string> */
    private readonly array $resetProps;
    /** @var list<string> */
    private readonly array $loadedOnceProps;
    private readonly ?string $errorBag;

    /** @var array<string, list<string>> */
    private array $deferredProps = [];
    /** @var list<string> */
    private array $rescuedProps = [];
    /** @var list<string> */
    private array $mergeProps = [];
    /** @var list<string> */
    private array $prependProps = [];
    /** @var list<string> */
    private array $deepMergeProps = [];
    /** @var list<string> */
    private array $matchPropsOn = [];
    /** @var array<string, array<string, mixed>> */
    private array $scrollProps = [];
    /** @var array<string, array{prop: string, expiresAt: int|null}> */
    private array $onceProps = [];
    /** @var list<string> */
    private array $sharedPropKeys = [];

    public function __construct(private readonly ServerRequestInterface $request, string $component)
    {
        $this->partial = $request->hasHeader(Header::PARTIAL_COMPONENT)
            && $request->getHeaderLine(Header::PARTIAL_COMPONENT) === $component;
        $this->inertia = $request->hasHeader(Header::INERTIA);
        $this->only = self::header($request, Header::PARTIAL_DATA);
        $this->except = self::header($request, Header::PARTIAL_EXCEPT);
        $this->resetProps = self::header($request, Header::RESET) ?? [];
        $this->loadedOnceProps = self::header($request, Header::EXCEPT_ONCE_PROPS) ?? [];
        $bag = trim($request->getHeaderLine(Header::ERROR_BAG));
        $this->errorBag = $bag === '' ? null : $bag;
    }

    /**
     * @param  array<string, mixed> $shared
     * @param  array<string, mixed> $props
     * @return array{0: array<array-key, mixed>, 1: array<string, mixed>} the props, and the metadata
     */
    public function resolve(array $shared, array $props): array
    {
        $shared = $this->wrapErrorBag($shared);
        $this->sharedPropKeys = array_values(array_unique(array_map(
            static fn (string $key): string => str_contains($key, '.') ? strstr($key, '.', true) ?: $key : $key,
            array_map('strval', array_keys($shared)),
        )));

        $merged = [...$shared, ...$props];

        return [
            $this->resolveProps($this->unpackDotProps($merged)),
            $this->metadata(),
        ];
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return array_filter([
            'sharedProps'    => $this->sharedPropKeys,
            'mergeProps'     => $this->mergeProps,
            'prependProps'   => $this->prependProps,
            'deepMergeProps' => $this->deepMergeProps,
            'matchPropsOn'   => $this->matchPropsOn,
            'deferredProps'  => $this->deferredProps,
            'rescuedProps'   => $this->rescuedProps,
            'scrollProps'    => $this->scrollProps,
            'onceProps'      => $this->onceProps,
        ], static fn (array $value): bool => $value !== []);
    }

    /**
     * @param  array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    private function resolveProps(array $props, string $prefix = '', bool $parentWasResolved = false): array
    {
        $result = [];

        foreach ($props as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $prop = $value;

            if (!$this->shouldIncludeInPartialResponse($prop, $path, $parentWasResolved)) {
                continue;
            }

            if (!$this->partial && $this->excludeFromInitialResponse($prop, $path)) {
                continue;
            }

            $value = $this->resolveValue($prop, $path);

            if (in_array($path, $this->rescuedProps, true)) {
                continue;
            }

            // A closure may return a prop type; unwrap once more so it takes
            // part in filtering and metadata like a declared one.
            if ($value !== $prop && self::isPropType($value)) {
                $prop = $value;

                if (!$this->partial && $this->excludeFromInitialResponse($prop, $path)) {
                    continue;
                }

                $value = $this->resolveValue($prop, $path);
            }

            $this->collectMetadata($prop, $path);

            $result[$key] = is_array($value)
                ? $this->resolveProps($value, $path, $parentWasResolved || !is_array($prop))
                : $value;
        }

        return $result;
    }

    private function shouldIncludeInPartialResponse(mixed $prop, string $path, bool $parentWasResolved): bool
    {
        if (!$this->partial || $prop instanceof AlwaysProp || $parentWasResolved) {
            return true;
        }

        if ($this->only !== null && !$this->matchesOnly($path) && !$this->leadsToOnly($path)) {
            return false;
        }

        return !($this->except !== null && $this->matchesExcept($path));
    }

    private function excludeFromInitialResponse(mixed $prop, string $path): bool
    {
        if ($prop instanceof IgnoreFirstLoad) {
            if ($prop instanceof Deferrable && $prop->shouldDefer() && !$this->wasAlreadyLoadedByClient($prop, $path)) {
                $this->deferredProps[$prop->group()][] = $path;
            }

            if ($prop instanceof Mergeable && $prop->shouldMerge()) {
                $this->collectMergeableMetadata($path, $prop);
            }

            if ($prop instanceof Onceable && $prop->shouldResolveOnce()) {
                $this->collectOnceMetadata($path, $prop);
            }

            return true;
        }

        if ($prop instanceof Deferrable && $prop->shouldDefer()) {
            $this->deferredProps[$prop->group()][] = $path;

            if ($prop instanceof Mergeable && $prop->shouldMerge()) {
                $this->collectMergeableMetadata($path, $prop);
            }

            return true;
        }

        if ($this->inertia && $this->wasAlreadyLoadedByClient($prop, $path)) {
            if ($prop instanceof Onceable) {
                $this->collectOnceMetadata($path, $prop);
            }

            return true;
        }

        return false;
    }

    private function wasAlreadyLoadedByClient(mixed $prop, string $path): bool
    {
        return $prop instanceof Onceable
            && $prop->shouldResolveOnce()
            && !$prop->shouldBeRefreshed()
            && in_array($prop->getKey() ?? $path, $this->loadedOnceProps, true);
    }

    private function resolveValue(mixed $value, string $path): mixed
    {
        if ($value instanceof ScrollProp) {
            $value->configureMergeIntent($this->request);
        }

        $rescue = $value instanceof Rescuable && $value->shouldRescue();

        try {
            return self::plain(self::call($value));
        } catch (\Throwable $e) {
            if (!$rescue) {
                throw $e;
            }

            $this->rescuedProps[] = $path;

            return null;
        }
    }

    /** Call what is callable: a closure, or a prop (invokable). */
    private static function call(mixed $value): mixed
    {
        return $value instanceof \Closure || (is_object($value) && is_callable($value)) ? $value() : $value;
    }

    /** A value the page can carry: objects that know how to become arrays do. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    private static function isPropType(mixed $value): bool
    {
        return $value instanceof AlwaysProp
            || $value instanceof Deferrable
            || $value instanceof IgnoreFirstLoad
            || $value instanceof Mergeable
            || $value instanceof Onceable;
    }

    private function collectMetadata(mixed $prop, string $path): void
    {
        if ($prop instanceof Mergeable && $prop->shouldMerge()) {
            $this->collectMergeableMetadata($path, $prop);
        }

        if ($prop instanceof ScrollProp) {
            $this->scrollProps[$path] = [
                ...$prop->metadata(),
                'reset' => in_array($path, $this->resetProps, true),
            ];
        }

        if ($prop instanceof Onceable && $prop->shouldResolveOnce()) {
            $this->collectOnceMetadata($path, $prop);
        }
    }

    private function collectMergeableMetadata(string $path, Mergeable $prop): void
    {
        if (in_array($path, $this->resetProps, true)) {
            return;
        }

        if ($this->partial && !$this->isIncludedInPartialMetadata($path)) {
            return;
        }

        if ($prop->shouldDeepMerge()) {
            $this->deepMergeProps[] = $path;
        } elseif ($prop->appendsAtRoot()) {
            $this->mergeProps[] = $path;
        } elseif ($prop->prependsAtRoot()) {
            $this->prependProps[] = $path;
        } else {
            foreach ($prop->appendsAtPaths() as $appendPath) {
                $this->mergeProps[] = "{$path}.{$appendPath}";
            }

            foreach ($prop->prependsAtPaths() as $prependPath) {
                $this->prependProps[] = "{$path}.{$prependPath}";
            }
        }

        foreach ($prop->matchesOn() as $strategy) {
            $this->matchPropsOn[] = "{$path}.{$strategy}";
        }
    }

    private function collectOnceMetadata(string $path, Onceable $prop): void
    {
        if (!$prop->shouldResolveOnce()) {
            return;
        }

        if ($this->partial && !$this->isIncludedInPartialMetadata($path)) {
            return;
        }

        $this->onceProps[$prop->getKey() ?? $path] = [
            'prop'      => $path,
            'expiresAt' => $prop->expiresAt(),
        ];
    }

    private function isIncludedInPartialMetadata(string $path): bool
    {
        if ($this->only !== null && !$this->matchesOnly($path)) {
            return false;
        }

        return !($this->except !== null && $this->matchesExcept($path));
    }

    private function matchesOnly(string $path): bool
    {
        foreach ($this->only ?? [] as $onlyPath) {
            if ($path === $onlyPath || str_starts_with($path, $onlyPath.'.')) {
                return true;
            }
        }

        return false;
    }

    private function leadsToOnly(string $path): bool
    {
        foreach ($this->only ?? [] as $onlyPath) {
            if (str_starts_with($onlyPath, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    private function matchesExcept(string $path): bool
    {
        foreach ($this->except ?? [] as $exceptPath) {
            if ($path === $exceptPath || str_starts_with($path, $exceptPath.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `'user.name' => 'Ada'` becomes `['user' => ['name' => 'Ada']]`, computing
     * a closure on the way when the path runs through one.
     *
     * @param  array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    private function unpackDotProps(array $props): array
    {
        foreach ($props as $key => $value) {
            if (!is_string($key) || !str_contains($key, '.')) {
                continue;
            }

            $value = self::plain(self::call($value));
            $segments = explode('.', $key);
            $last = array_pop($segments);

            $current = &$props;
            $traversable = true;

            foreach ($segments as $segment) {
                if (!isset($current[$segment])) {
                    $current[$segment] = [];
                } else {
                    $current[$segment] = self::plain(self::call($current[$segment]));
                }

                if (!is_array($current[$segment])) {
                    $traversable = false;
                    break;
                }

                $current = &$current[$segment];
            }

            if ($traversable) {
                $current[$last] = $value;
            }

            unset($current, $props[$key]);
        }

        return $props;
    }

    /**
     * Validation errors under the bag the client asked for, so a form with
     * its own bag reads only its own errors.
     *
     * @param  array<string, mixed> $shared
     * @return array<string, mixed>
     */
    private function wrapErrorBag(array $shared): array
    {
        if ($this->errorBag === null || !array_key_exists('errors', $shared)) {
            return $shared;
        }

        $errors = $shared['errors'];
        $shared['errors'] = AlwaysProp::of(function () use ($errors): array {
            $errors = self::plain(self::call($errors));

            return [$this->errorBag => is_array($errors) ? $errors : []];
        });

        return $shared;
    }

    /** @return list<string>|null */
    private static function header(ServerRequestInterface $request, string $name): ?array
    {
        $values = array_values(array_filter(
            array_map('trim', explode(',', $request->getHeaderLine($name))),
            static fn (string $value): bool => $value !== '',
        ));

        return $values === [] ? null : $values;
    }
}
