<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo;

final class DemoService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(public readonly array $config)
    {
    }
}
