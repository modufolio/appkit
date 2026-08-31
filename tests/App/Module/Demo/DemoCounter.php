<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo;

final class DemoCounter
{
    public function __construct(public readonly int $perPage)
    {
    }
}
