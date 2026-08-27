<?php

/**
 * JSON:API fixture: a roles map with a key the loader does not know.
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
        ],
        'roles' => [
            'admin' => ['ROLE_ADMIN'],
        ],
    ]);
};
