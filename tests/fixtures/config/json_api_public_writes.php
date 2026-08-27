<?php

/**
 * JSON:API fixture: writes deliberately left public via the explicit
 * `'read' => [], 'write' => []` opt-out.
 */

use Modufolio\Appkit\Tests\App\Entity\Account;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    $api->setResourceConfig(Account::class, [
        'resource_key' => 'account',
        'fields' => ['id', 'name'],
        'relationships' => [],
        'operations' => [
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ],
        'roles' => [
            'read' => [],
            'write' => [],
        ],
    ]);
};
