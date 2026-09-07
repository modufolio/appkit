<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;


/**
 * A controller the kernel never wired: absent from controllers.php, present
 * in container.php through load(), autowired by Symfony, public by the
 * "Controller" suffix.
 */
final class GreeterController
{
    public function __construct(public readonly Greeter $greeter)
    {
    }
}
