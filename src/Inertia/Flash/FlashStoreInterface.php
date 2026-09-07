<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Flash;

/**
 * Where flash data waits between a redirect and the page that follows it.
 *
 * `Inertia::flash()` on a response, or `InertiaRenderer::flash()` before a
 * redirect, puts data here; the next page pulls it into its top-level
 * `flash` key, which the client exposes as `usePage().flash` and clears on
 * the following visit. A session-backed store survives the redirect;
 * {@see ArrayFlashStore} does not, and is for tests and single-response use.
 */
interface FlashStoreInterface
{
    /** @param array<string, mixed> $data merged over what is already waiting */
    public function put(array $data): void;

    /** @return array<string, mixed> what is waiting, without removing it */
    public function peek(): array;

    /** @return array<string, mixed> what was waiting; the store is empty afterwards */
    public function pull(): array;
}
