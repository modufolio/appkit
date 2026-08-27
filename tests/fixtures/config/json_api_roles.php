<?php

/**
 * JSON:API role-declaration fixtures: one entity per declaration shape.
 */

use Modufolio\Appkit\Tests\App\Entity\Account;
use Modufolio\Appkit\Tests\App\Entity\Contact;
use Modufolio\Appkit\Tests\App\Entity\Organization;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    // Flat list: one gate for every route of the entity.
    $api->resource(Account::class)
        ->key('account')
        ->fields(['id', 'name'])
        ->relationships([])
        ->operations([
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ])
        ->roles(['ROLE_API_USER']);

    // Split shape: declared via setResourceConfig() because the fluent
    // roles() only accepts a flat list.
    $api->setResourceConfig(Contact::class, [
        'resource_key' => 'contact',
        'fields' => ['id', 'firstName'],
        'relationships' => [],
        'operations' => [
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ],
        'roles' => [
            'read' => ['ROLE_USER'],
            'write' => ['ROLE_ADMIN'],
        ],
    ]);

    // No roles and no write operations: nothing to gate.
    $api->setResourceConfig(Organization::class, [
        'resource_key' => 'organization',
        'fields' => ['id', 'name'],
        'relationships' => [],
        'operations' => [
            'index' => true,
            'show' => true,
        ],
        'roles' => [],
    ]);
};
