<?php

declare(strict_types=1);

use Modufolio\Appkit\Core\Env;

const BASE_DIR = __DIR__;

// Load composer autoloader
require __DIR__.'/vendor/autoload.php';

// Publish the environment before anything reads configuration. Once frozen it
// is immutable and reachable anywhere through env() / Env::instance().
(new Env())->fromFile(__DIR__.'/.env')->freeze();

// ParaTest runs each worker in its own process and exports TEST_TOKEN. Give
// that worker a private writable directory so sessions and Doctrine proxies
// never collide with a sibling worker. Mirrors AppFactory::varDir().
$testToken = getenv('TEST_TOKEN');
if (false !== $testToken && '' !== $testToken) {
    $workerVarDir = __DIR__.'/var/test/'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $testToken);

    if (!is_dir($workerVarDir.'/sessions')) {
        mkdir($workerVarDir.'/sessions', 0o777, true);
    }
}
