<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

/**
 * A controller without the "Controller" suffix: reachable as one only
 * because container.php tags it appkit.controller.
 */
final class GreeterEndpoint
{
    public function __construct(public readonly Greeter $greeter)
    {
    }
}
