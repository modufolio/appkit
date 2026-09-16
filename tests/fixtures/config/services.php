<?php

declare(strict_types=1);

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\RootViewInterface;
use Modufolio\Appkit\Inertia\SharedPropsInterface;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

// The kernel wires its own core services (router, session, entity manager,
// CSRF, serializer, …); this file only declares what the test app adds.
return function (ServiceConfigurator $services): void {
    $services
        // The notification seam. One instance for the process, as the kernel
        // keeps it; tests read what was dispatched and clear it between runs.
        ->set(EventDispatcherInterface::class, fn () => RecordingEventDispatcher::instance())
        ->set(TotpService::class, fn (App $app) => $app->totpService())
        ->set(BruteForceProtectionInterface::class, fn (App $app) => $app->bruteForceProtection())
        // Inertia: the document a first visit receives, and the props every page carries.
        ->set(RootViewInterface::class, fn () => new CallableRootView(
            static fn (Page $page): string => '<html><body>'.Inertia::snippet($page).'</body></html>',
        ))
        ->set(SharedPropsInterface::class, fn () => new class implements SharedPropsInterface {
            public function create(): array
            {
                return ['auth' => ['user' => null]];
            }
        });
};
