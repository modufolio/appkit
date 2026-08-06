<?php

declare(strict_types=1);

use Modufolio\Appkit\Core\Env;

const BASE_DIR = __DIR__;

// Load composer autoloader
require __DIR__.'/vendor/autoload.php';

// Publish the environment before anything reads configuration. Once frozen it
// is immutable and reachable anywhere through env() / Env::instance().
(new Env())->fromFile(__DIR__.'/.env')->freeze();
