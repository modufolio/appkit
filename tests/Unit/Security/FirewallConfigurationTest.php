<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\FirewallConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Pure schema coverage for the firewall config, driven straight through the
 * Symfony Processor — env-independent and fast, unlike the app-level wiring
 * tests in tests/Unit/App/FirewallConfigurationTest.php.
 */
#[CoversClass(FirewallConfiguration::class)]
class FirewallConfigurationTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $firewalls
     *
     * @return array<string, array<string, mixed>>
     */
    private function process(array $firewalls): array
    {
        return (new Processor())->processConfiguration(
            new FirewallConfiguration(),
            [['firewalls' => $firewalls]],
        )['firewalls'];
    }

    public function testValidConfigPasses(): void
    {
        $result = $this->process([
            'main' => [
                'pattern' => '/panel',
                'authenticators' => ['form_login', 'remember_me'],
                'entry_point' => '/panel/login',
                'stateless' => false,
                'logout' => ['path' => '/panel/logout', 'target' => '/panel/login'],
            ],
        ]);

        $this->assertSame('/panel', $result['main']['pattern']);
        $this->assertSame(['form_login', 'remember_me'], $result['main']['authenticators']);
    }

    public function testFirewallRestrictionKeysAreAccepted(): void
    {
        // methods/host/ips are honoured firewall restrictions (Symfony-style).
        $result = $this->process([
            'main' => [
                'pattern' => '/api',
                'methods' => ['GET', 'HEAD'],
                'host' => 'api.example.com',
                'ips' => ['10.0.0.0/8'],
            ],
        ]);

        $this->assertSame(['GET', 'HEAD'], $result['main']['methods']);
        $this->assertSame('api.example.com', $result['main']['host']);
        $this->assertSame(['10.0.0.0/8'], $result['main']['ips']);
    }

    public function testUnknownKeysArePreserved(): void
    {
        // App-specific keys (read by the app's own ApplicationState) must survive.
        $result = $this->process([
            'main' => [
                'pattern' => '/',
                'context' => 'app',
                'tenant_header' => 'X-Tenant',
            ],
        ]);

        $this->assertSame('app', $result['main']['context']);
        $this->assertSame('X-Tenant', $result['main']['tenant_header']);
    }

    public function testSwitchUserDefaultsAreFilledIn(): void
    {
        $result = $this->process([
            'main' => [
                'pattern' => '/',
                'switch_user' => ['enabled' => true, 'role' => 'ROLE_SUPER_ADMIN'],
            ],
        ]);

        $this->assertSame([
            'enabled' => true,
            'role' => 'ROLE_SUPER_ADMIN',
            'parameter' => '_switch_user',
            'target' => null,
        ], $result['main']['switch_user']);
    }

    public function testSwitchUserIsDisabledUnlessEnabled(): void
    {
        // Declaring the section must not be enough to turn impersonation on.
        $result = $this->process(['main' => ['pattern' => '/']]);

        $this->assertFalse($result['main']['switch_user']['enabled']);
    }

    public function testSwitchUserRejectsEmptyRole(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'main' => ['pattern' => '/', 'switch_user' => ['enabled' => true, 'role' => '']],
        ]);
    }

    public function testWrongScalarTypeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['main' => ['pattern' => '/', 'stateless' => 'yes']]);
    }

    public function testClosureCsrfValidatorIsAccepted(): void
    {
        $validator = static fn (): bool => true;

        $result = $this->process(['main' => ['pattern' => '/', 'csrf_validator' => $validator]]);

        $this->assertSame($validator, $result['main']['csrf_validator']);
    }

    public function testNonCallableCsrfValidatorIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('csrf_validator');

        $this->process(['main' => ['pattern' => '/', 'csrf_validator' => 'not-callable']]);
    }

    public function testEmptyPatternIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['main' => ['pattern' => '']]);
    }
}
