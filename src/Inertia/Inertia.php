<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Inertia\Props\AlwaysProp;
use Modufolio\Appkit\Inertia\Props\DeferredProp;
use Modufolio\Appkit\Inertia\Props\MergeProp;
use Modufolio\Appkit\Inertia\Props\OnceProp;
use Modufolio\Appkit\Inertia\Props\OptionalProp;
use Modufolio\Appkit\Inertia\Props\ProvidesScrollMetadata;
use Modufolio\Appkit\Inertia\Props\ScrollProp;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * An Inertia page, as a controller returns it: the component, its props, and
 * what this response asks of the client — flash data, history flags, the
 * URL fragment. A value and nothing more; it knows neither the request nor
 * how it is delivered.
 *
 * ```php
 * public function edit(User $user): Inertia
 * {
 *     return Inertia::render('Users/Edit', [
 *         'user'     => $user,
 *         'roles'    => fn () => $roles->all(),                 // computed only if sent
 *         'activity' => Inertia::defer(fn () => $log->for($user)),
 *         'feed'     => Inertia::scroll($page->items, ScrollMetadata::fromPage(2, 25, 90)),
 *     ])->flash('saved', true);
 * }
 * ```
 *
 * The kernel finishes it: {@see \Modufolio\Appkit\Core\Kernel::inertia()}
 * hands the page to the {@see InertiaRenderer}, which knows the root view,
 * the asset version, the shared props and the flash store, and builds the
 * page object against the request's partial-reload headers — as JSON for
 * the client's XHR, or inside the host's HTML document on a first visit.
 * Outside the kernel, `$renderer->respond($page, $request)` does the same.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class Inertia
{
    private bool $encryptHistory = false;
    private bool $clearHistory = false;
    private bool $preserveFragment = false;
    /** @var array<string, mixed> */
    private array $flash = [];

    /** @param array<string, mixed> $props */
    public function __construct(
        private readonly string $component,
        private array $props = [],
    ) {
    }

    /** @param array<string, mixed> $props */
    public static function render(string $component, array $props = []): self
    {
        return new self($component, $props);
    }

    // ── Prop kinds ───────────────────────────────────────────────────────────

    /** Sent only when a partial reload names it. */
    public static function optional(\Closure $value): OptionalProp
    {
        return OptionalProp::of($value);
    }

    /**
     * Left out of the first response; the client fetches it right after
     * rendering. `$rescue` turns a failure into null and a `rescuedProps`
     * entry instead of an error page.
     */
    public static function defer(\Closure $value, string $group = 'default', bool $rescue = false): DeferredProp
    {
        return DeferredProp::of($value, $group, $rescue);
    }

    /** Sent on every response, partial reloads included. */
    public static function always(mixed $value): AlwaysProp
    {
        return AlwaysProp::of($value);
    }

    /** Merged into what the client holds instead of replacing it. */
    public static function merge(mixed $value): MergeProp
    {
        return MergeProp::of($value);
    }

    /** Merged recursively. */
    public static function deepMerge(mixed $value): MergeProp
    {
        return MergeProp::of($value, deep: true);
    }

    /** Sent once, then kept by the client across visits until it expires. */
    public static function once(\Closure $value): OnceProp
    {
        return OnceProp::of($value);
    }

    /**
     * A page of an infinitely scrolling list: merged inside `$wrapper`,
     * appended or prepended as the client asks, with the page metadata the
     * client's scroll component reads.
     *
     * @param ProvidesScrollMetadata|\Closure(mixed): ProvidesScrollMetadata $metadata
     */
    public static function scroll(mixed $value, ProvidesScrollMetadata|\Closure $metadata, string $wrapper = 'data'): ScrollProp
    {
        return ScrollProp::of($value, $metadata, $wrapper);
    }

    // ── Building ─────────────────────────────────────────────────────────────

    /**
     * Add props; a key given twice takes the later value.
     *
     * @param string|array<string, mixed> $key
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->props = [...$this->props, ...$key];
        } else {
            $this->props[$key] = $value;
        }

        return $this;
    }

    /**
     * Flash data for this response: the client shows it as `usePage().flash`
     * and clears it on the next visit. For data that must survive a redirect
     * first, flash on the renderer instead.
     *
     * @param string|array<string, mixed> $key
     */
    public function flash(string|array $key, mixed $value = null): self
    {
        $this->flash = [...$this->flash, ...(is_array($key) ? $key : [$key => $value])];

        return $this;
    }

    /** Ask the client to encrypt this page's history entry (sensitive props). */
    public function encryptHistory(bool $encrypt = true): self
    {
        $this->encryptHistory = $encrypt;

        return $this;
    }

    /** Ask the client to drop its history state on this visit (after logout, say). */
    public function clearHistory(bool $clear = true): self
    {
        $this->clearHistory = $clear;

        return $this;
    }

    /** Keep the URL fragment the client navigated with instead of the response's. */
    public function preserveFragment(bool $preserve = true): self
    {
        $this->preserveFragment = $preserve;

        return $this;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function component(): string
    {
        return $this->component;
    }

    /**
     * The props as declared: values, closures and Prop instances, unresolved.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return $this->props;
    }

    /** @return array<string, mixed> */
    public function flashed(): array
    {
        return $this->flash;
    }

    public function encryptsHistory(): bool
    {
        return $this->encryptHistory;
    }

    public function clearsHistory(): bool
    {
        return $this->clearHistory;
    }

    public function preservesFragment(): bool
    {
        return $this->preserveFragment;
    }

    // ── Responses that are not pages ─────────────────────────────────────────

    /**
     * An Inertia "location" response: the client visits the URL in full. For
     * leaving the app — a download, an external page, a stale-asset reload —
     * where a redirect would be followed with XHR and fail.
     */
    public static function location(string $url): ResponseInterface
    {
        return (new Response())
            ->withStatus(409)
            ->withHeader(Header::LOCATION, $url)
            ->withHeader('Vary', Header::INERTIA);
    }

    /**
     * What the client boots from, for a root view: the page object in a
     * JSON script element the client looks up by `data-page`, and the empty
     * element it mounts into. The form `@inertiajs/*` 3 reads (a `data-page`
     * attribute on the mount element was the earlier one). The JSON escapes
     * `<`, so a value can never close the script element.
     */
    public static function snippet(Page $page, string $id = 'app'): string
    {
        $id = htmlspecialchars($id, ENT_QUOTES);

        return sprintf(
            '<script data-page="%s" type="application/json">%s</script><div id="%s"></div>',
            $id,
            $page->toJson(),
            $id,
        );
    }
}
