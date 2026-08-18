<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

/**
 * Implemented by controllers (or any kernel-instantiated object) that want the
 * application handed to them right after construction.
 *
 * The Kernel calls setSubscribedServices() before the controller method runs,
 * so implementations can pull exactly the services they need. Extending
 * AbstractController gives a ready-made implementation with the common
 * services; implement this interface directly to design your own subset.
 */
interface AppAwareInterface
{
    public function setSubscribedServices(AppInterface $app): void;
}
