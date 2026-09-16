<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Defs;

final class Greeter implements GreeterInterface
{
    public function __construct(public readonly string $word)
    {
    }
}
