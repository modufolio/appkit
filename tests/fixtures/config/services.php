<?php

declare(strict_types=1);

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Tests\App\App;

// The kernel wires its own core services (router, session, entity manager,
// CSRF, serializer, …); this file only declares what the test app adds.
return function (ServiceConfigurator $services): void {
    $services
        ->set(TotpService::class, fn (App $app) => $app->totpService())
        ->set(BruteForceProtectionInterface::class, fn (App $app) => $app->bruteForceProtection());
};
