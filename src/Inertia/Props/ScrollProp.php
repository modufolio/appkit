<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

use Modufolio\Appkit\Inertia\Header;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A paginated list the client scrolls through: merged inside its wrapper
 * (`data`), appended or prepended as the request's
 * `X-Inertia-Infinite-Scroll-Merge-Intent` says, with `scrollProps`
 * metadata naming the page parameter and the previous, next and current
 * page. Optionally deferred.
 *
 * The metadata comes from a {@see ProvidesScrollMetadata}, or from a callable
 * given the resolved value — this package knows no paginator class, so say
 * where the page numbers are: `ScrollMetadata::fromPage($page, $perPage,
 * $total)` covers the common case.
 */
final class ScrollProp extends Prop implements Deferrable, Mergeable
{
    use DefersProps;
    use MergesProps;

    private bool $resolved = false;
    private mixed $resolvedValue = null;

    /** @param ProvidesScrollMetadata|\Closure(mixed): ProvidesScrollMetadata $metadata */
    private function __construct(
        mixed $value,
        private readonly string $wrapper,
        private readonly ProvidesScrollMetadata|\Closure $metadata,
    ) {
        parent::__construct($value);
        $this->merge = true;
    }

    /** @param ProvidesScrollMetadata|\Closure(mixed): ProvidesScrollMetadata $metadata */
    public static function of(mixed $value, ProvidesScrollMetadata|\Closure $metadata, string $wrapper = 'data'): self
    {
        return new self($value, $wrapper, $metadata);
    }

    public function __invoke(): mixed
    {
        if (!$this->resolved) {
            $this->resolvedValue = parent::__invoke();
            $this->resolved = true;
        }

        return $this->resolvedValue;
    }

    public function configureMergeIntent(ServerRequestInterface $request): static
    {
        return $request->getHeaderLine(Header::INFINITE_SCROLL_MERGE_INTENT) === 'prepend'
            ? $this->prepend($this->wrapper)
            : $this->append($this->wrapper);
    }

    /** @return array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null} */
    public function metadata(): array
    {
        $provider = $this->metadata instanceof ProvidesScrollMetadata
            ? $this->metadata
            : ($this->metadata)($this());

        return [
            'pageName'     => $provider->getPageName(),
            'previousPage' => $provider->getPreviousPage(),
            'nextPage'     => $provider->getNextPage(),
            'currentPage'  => $provider->getCurrentPage(),
        ];
    }
}
