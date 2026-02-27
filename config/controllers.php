<?php

declare(strict_types=1);

use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Tests\App\Controller\TwoFactorController;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

return [

    TwoFactorController::class => [
        TotpService::class,
        TokenStorageInterface::class,
        SessionInterface::class,
        CsrfTokenManagerInterface::class,
        UrlGeneratorInterface::class,
    ],

];
