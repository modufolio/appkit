<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing;

use Modufolio\Appkit\Routing\Loader\JsonApiRouteLoader;
use Modufolio\Appkit\Tests\App\Entity\Account;
use Modufolio\Appkit\Tests\App\Entity\Contact;
use Modufolio\Appkit\Tests\App\Entity\Organization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class JsonApiRouteLoaderTest extends TestCase
{
    private JsonApiRouteLoader $loader;
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = __DIR__ . '/../../..';

        $fileLocator = new FileLocator($this->configDir);
        $this->loader = new JsonApiRouteLoader(
            fileLocator: $fileLocator,
            controllerClass: 'App\JsonApi\JsonApiController',
            prefix: '/api',
            debug: true
        );
    }

    public function testSupportsJsonApiType(): void
    {
        $this->assertTrue($this->loader->supports('config/json_api.php', 'json_api'));
    }

    public function testDoesNotSupportOtherTypes(): void
    {
        $this->assertFalse($this->loader->supports('config/json_api.php', 'yaml'));
        $this->assertFalse($this->loader->supports('config/json_api.php', 'xml'));
    }

    public function testLoadReturnsRouteCollection(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $this->assertInstanceOf(RouteCollection::class, $routes);
    }

    public function testLoadsAccountRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Account index route
        $indexRoute = $routes->get('api_account_index');
        $this->assertNotNull($indexRoute);
        $this->assertInstanceOf(Route::class, $indexRoute);
        $this->assertSame('/api/account', $indexRoute->getPath());
        $this->assertSame(['GET'], $indexRoute->getMethods());
        $this->assertSame(Account::class, $indexRoute->getDefault('entityClass'));
        $this->assertSame('index', $indexRoute->getDefault('operation'));

        // Account show route
        $showRoute = $routes->get('api_account_show');
        $this->assertNotNull($showRoute);
        $this->assertSame('/api/account/{id}', $showRoute->getPath());
        $this->assertSame(['GET'], $showRoute->getMethods());
        $this->assertSame('show', $showRoute->getDefault('operation'));

        // Account create route
        $createRoute = $routes->get('api_account_create');
        $this->assertNotNull($createRoute);
        $this->assertSame('/api/account', $createRoute->getPath());
        $this->assertSame(['POST'], $createRoute->getMethods());
        $this->assertSame('create', $createRoute->getDefault('operation'));

        // Account update route
        $updateRoute = $routes->get('api_account_update');
        $this->assertNotNull($updateRoute);
        $this->assertSame('/api/account/{id}', $updateRoute->getPath());
        $this->assertSame(['PATCH', 'PUT'], $updateRoute->getMethods());
        $this->assertSame('update', $updateRoute->getDefault('operation'));

        // Account delete route
        $deleteRoute = $routes->get('api_account_delete');
        $this->assertNotNull($deleteRoute);
        $this->assertSame('/api/account/{id}', $deleteRoute->getPath());
        $this->assertSame(['DELETE'], $deleteRoute->getMethods());
        $this->assertSame('delete', $deleteRoute->getDefault('operation'));
    }

    public function testLoadsAccountRelationshipRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Account related organizations
        $orgRoute = $routes->get('api_account_related_organizations');
        $this->assertNotNull($orgRoute);
        $this->assertSame('/api/account/{id}/organizations', $orgRoute->getPath());
        $this->assertSame(['GET'], $orgRoute->getMethods());
        $this->assertSame('related', $orgRoute->getDefault('operation'));
        $this->assertSame('organizations', $orgRoute->getDefault('relationship'));

        // Account related contacts
        $contactRoute = $routes->get('api_account_related_contacts');
        $this->assertNotNull($contactRoute);
        $this->assertSame('/api/account/{id}/contacts', $contactRoute->getPath());
        $this->assertSame('contacts', $contactRoute->getDefault('relationship'));
    }

    public function testLoadsContactRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Contact index route
        $indexRoute = $routes->get('api_contact_index');
        $this->assertNotNull($indexRoute);
        $this->assertSame('/api/contact', $indexRoute->getPath());
        $this->assertSame(Contact::class, $indexRoute->getDefault('entityClass'));

        // Contact show route
        $showRoute = $routes->get('api_contact_show');
        $this->assertNotNull($showRoute);
        $this->assertSame('/api/contact/{id}', $showRoute->getPath());

        // Contact create, update, delete routes
        $this->assertNotNull($routes->get('api_contact_create'));
        $this->assertNotNull($routes->get('api_contact_update'));
        $this->assertNotNull($routes->get('api_contact_delete'));
    }

    public function testLoadsContactRelationshipRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Contact related account
        $accountRoute = $routes->get('api_contact_related_account');
        $this->assertNotNull($accountRoute);
        $this->assertSame('/api/contact/{id}/account', $accountRoute->getPath());
        $this->assertSame('account', $accountRoute->getDefault('relationship'));

        // Contact related organization
        $orgRoute = $routes->get('api_contact_related_organization');
        $this->assertNotNull($orgRoute);
        $this->assertSame('/api/contact/{id}/organization', $orgRoute->getPath());
        $this->assertSame('organization', $orgRoute->getDefault('relationship'));
    }

    public function testLoadsOrganizationRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Organization index route
        $indexRoute = $routes->get('api_organization_index');
        $this->assertNotNull($indexRoute);
        $this->assertSame('/api/organization', $indexRoute->getPath());
        $this->assertSame(Organization::class, $indexRoute->getDefault('entityClass'));

        // Organization show route
        $this->assertNotNull($routes->get('api_organization_show'));

        // Organization CRUD routes
        $this->assertNotNull($routes->get('api_organization_create'));
        $this->assertNotNull($routes->get('api_organization_update'));
        $this->assertNotNull($routes->get('api_organization_delete'));
    }

    public function testLoadsOrganizationRelationshipRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Organization related account
        $accountRoute = $routes->get('api_organization_related_account');
        $this->assertNotNull($accountRoute);
        $this->assertSame('/api/organization/{id}/account', $accountRoute->getPath());
        $this->assertSame('account', $accountRoute->getDefault('relationship'));

        // Organization related contacts
        $contactRoute = $routes->get('api_organization_related_contacts');
        $this->assertNotNull($contactRoute);
        $this->assertSame('/api/organization/{id}/contacts', $contactRoute->getPath());
        $this->assertSame('contacts', $contactRoute->getDefault('relationship'));
    }

    public function testIdRequirementOnShowRoute(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $showRoute = $routes->get('api_account_show');
        $this->assertSame('\d+', $showRoute->getRequirement('id'));
    }

    public function testIdRequirementOnUpdateRoute(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $updateRoute = $routes->get('api_account_update');
        $this->assertSame('\d+', $updateRoute->getRequirement('id'));
    }

    public function testIdRequirementOnDeleteRoute(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $deleteRoute = $routes->get('api_account_delete');
        $this->assertSame('\d+', $deleteRoute->getRequirement('id'));
    }

    public function testAllRoutesHaveController(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        foreach ($routes as $route) {
            $this->assertNotNull($route->getDefault('_controller'));
            $this->assertSame(['App\JsonApi\JsonApiController', 'handle'], $route->getDefault('_controller'));
        }
    }

    public function testAllRoutesHaveEntityClass(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        foreach ($routes as $route) {
            $this->assertNotNull($route->getDefault('entityClass'));
            $entityClass = $route->getDefault('entityClass');
            $this->assertTrue(
                in_array($entityClass, [Account::class, Contact::class, Organization::class]),
                "Unexpected entity class: $entityClass"
            );
        }
    }

    public function testAllRoutesHaveOperation(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $validOperations = ['index', 'show', 'create', 'update', 'delete', 'related'];

        foreach ($routes as $route) {
            $operation = $route->getDefault('operation');
            $this->assertNotNull($operation);
            $this->assertContains($operation, $validOperations);
        }
    }

    public function testCustomPrefixOnRoutes(): void
    {
        $fileLocator = new FileLocator($this->configDir);
        $loader = new JsonApiRouteLoader(
            fileLocator: $fileLocator,
            controllerClass: 'App\JsonApi\JsonApiController',
            prefix: '/v1/api',
            debug: true
        );

        $routes = $loader->load('config/json_api.php', 'json_api');

        $indexRoute = $routes->get('api_account_index');
        $this->assertSame('/v1/api/account', $indexRoute->getPath());
    }

    public function testRelationshipRoutesIncludeIdRequirement(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $relationshipRoute = $routes->get('api_account_related_organizations');
        $this->assertSame('\d+', $relationshipRoute->getRequirement('id'));
    }

    public function testTotalRouteCountForAllEntities(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Account: 5 operations (index, show, create, update, delete) + 2 relationships = 7
        // Contact: 5 operations + 2 relationships = 7
        // Organization: 5 operations + 2 relationships = 7
        // Total: 21
        $this->assertGreaterThan(20, count($routes));
    }

    public function testRelationshipRouteGetMethod(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // Relationship routes should only support GET
        $relationshipRoute = $routes->get('api_account_related_organizations');
        $this->assertSame(['GET'], $relationshipRoute->getMethods());
    }

    public function testCreateRouteHasPostMethod(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $createRoute = $routes->get('api_account_create');
        $this->assertSame(['POST'], $createRoute->getMethods());
    }

    public function testUpdateRouteHasPatchAndPutMethods(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $updateRoute = $routes->get('api_account_update');
        $this->assertSame(['PATCH', 'PUT'], $updateRoute->getMethods());
    }

    public function testIndexRouteHasNoIdParameter(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $indexRoute = $routes->get('api_account_index');
        $this->assertSame('/api/account', $indexRoute->getPath());
        $this->assertStringNotContainsString('{id}', $indexRoute->getPath());
    }

    public function testCreateRouteHasNoIdParameter(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $createRoute = $routes->get('api_account_create');
        $this->assertSame('/api/account', $createRoute->getPath());
        $this->assertStringNotContainsString('{id}', $createRoute->getPath());
    }

    public function testShowRouteHasIdParameter(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $showRoute = $routes->get('api_account_show');
        $this->assertStringContainsString('{id}', $showRoute->getPath());
    }

    public function testUpdateRouteHasIdParameter(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $updateRoute = $routes->get('api_account_update');
        $this->assertStringContainsString('{id}', $updateRoute->getPath());
    }

    public function testDeleteRouteHasIdParameter(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        $deleteRoute = $routes->get('api_account_delete');
        $this->assertStringContainsString('{id}', $deleteRoute->getPath());
    }

    public function testRouteNamesFollowConvention(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        // All routes should start with 'api_'
        foreach ($routes as $name => $route) {
            $this->assertStringStartsWith('api_', $name);
        }
    }

    public function testPrefixIsAppliedToAllRoutes(): void
    {
        $routes = $this->loader->load('config/json_api.php', 'json_api');

        foreach ($routes as $route) {
            $this->assertStringStartsWith('/api/', $route->getPath());
        }
    }

    public function testNonDebugModeWithoutResourceKey(): void
    {
        $fileLocator = new FileLocator($this->configDir);
        $loader = new JsonApiRouteLoader(
            fileLocator: $fileLocator,
            controllerClass: 'App\JsonApi\JsonApiController',
            prefix: '/api',
            debug: false
        );

        // In non-debug mode, should fall back to extracting resource key from class name
        $routes = $loader->load('config/json_api.php', 'json_api');

        // Routes should still be created
        $this->assertGreaterThan(0, count($routes));
    }
}
