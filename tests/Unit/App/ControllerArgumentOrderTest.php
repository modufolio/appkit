<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ControllerArgumentOrderTest extends AppTestCase
{
    /**
     * Controller arguments are matched by name, not by position.
     *
     * The resolver pipeline keys its results by parameter name and fills them
     * in resolver order: AssociativeArrayResolver matches the route parameters
     * first, TypeHintResolver supplies the request afterwards. Spreading that
     * array positionally handed argument #1 the route parameter and blew up
     * with a TypeError before reaching the controller body.
     */
    public function testRouteParametersDoNotDisplaceTheRequestArgument(): void
    {
        // The route only needs to be reachable anonymously; the firewall is
        // not what is under test here.
        $security = new SecurityConfigurator();
        $security->publicPath('/ordered');
        $this->app()->configureSecurity($security);

        $response = $this->get('/ordered/about/2');

        $response->assertStatus(200);

        $this->assertSame(
            'Modufolio\\Psr7\\Http\\ServerRequest|about|2|null',
            $response->getContent()
        );
    }
}
