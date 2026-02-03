<?php

namespace Modufolio\Appkit\Image;

/**
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface DarkroomInterface
{
    public function process(string $file, array $options = []): array;
}
