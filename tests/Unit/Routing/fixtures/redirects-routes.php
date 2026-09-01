<?php

declare(strict_types=1);

use Modufolio\Appkit\Routing\RedirectConfigurator;

return function (RedirectConfigurator $redirects): void {
    $redirects
        ->redirectToRoute('/old-blog', 'blog_index', [], 302)
        ->redirect('/a', '/b')
        ->redirect('/b', '/c');
};
