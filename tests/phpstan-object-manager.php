<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\AppFactory;

require __DIR__.'/../vendor/autoload.php';

return AppFactory::create(dirname(__DIR__, 1))->entityManager();
