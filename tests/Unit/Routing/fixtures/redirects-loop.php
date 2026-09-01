<?php

declare(strict_types=1);

use Modufolio\Appkit\Routing\RedirectConfigurator;

return function (RedirectConfigurator $redirects): void {
    $redirects
        ->redirect('/a', '/b')
        ->redirect('/b', '/c')
        ->redirect('/c', '/a')
        ->redirect('/self', '/self');
};
