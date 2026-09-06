<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo;

/**
 * Declared by the module's config/container.php — the bundle half — with the
 * module's own configuration reaching it as a Symfony parameter.
 */
final class DemoSymfonyService
{
    public function __construct(
        public readonly int $perPage,
        public readonly string $flavor,
    ) {
    }
}
