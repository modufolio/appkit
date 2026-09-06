<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Core\AppAwareInterface;
use Modufolio\Appkit\Core\AppInterface;

/**
 * A controller the kernel never wired: absent from controllers.php, present
 * in container.php through load(), autowired by Symfony, public by the
 * "Controller" suffix, and still handed the app because it is AppAware.
 */
final class GreeterController implements AppAwareInterface
{
    public ?AppInterface $app = null;

    public function __construct(public readonly Greeter $greeter)
    {
    }

    public function setSubscribedServices(AppInterface $app): void
    {
        $this->app = $app;
    }
}
