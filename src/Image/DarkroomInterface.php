<?php

namespace Modufolio\Appkit\Image;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface DarkroomInterface
{
    /**
     * Resolve the caller's options against the driver defaults and the source
     * image, returning the concrete options `process()` will act on.
     *
     * Consumers that regenerate a variant from a stored job need this step
     * before processing, so it belongs on the contract rather than only on the
     * abstract base — otherwise callers have to assert a concrete driver.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function preprocess(string $file, array $options = []): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function process(string $file, array $options = []): array;
}
