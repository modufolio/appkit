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
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function process(string $file, array $options = []): array;
}
