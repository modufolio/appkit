<?php

declare(strict_types=1);

use Modufolio\Appkit\Routing\RedirectConfigurator;

return function (RedirectConfigurator $redirects): void {
    $redirects
        ->redirect('/home', '/', 301)
        ->redirect('old-blog', '/blog', 302);
};
