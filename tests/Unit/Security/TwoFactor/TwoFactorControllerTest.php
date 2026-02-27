<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\TwoFactor;

use Modufolio\Appkit\Security\Authenticator\FormLoginAuthenticator;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorSecret;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities;
use Doctrine\DBAL\Schema\Schema;
use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class TwoFactorControllerTest extends AppTestCase
{
    use DatabaseTestingCapabilities {
        loadFixtures as loadDatabaseFixtures;
    }

    public function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->app()->entityManager()->getConnection();
    }

    /**
     * Override the trait's #[Before] hook without the attribute so PHPUnit does
     * not call it automatically. setUp() drives the initialisation order instead.
     */
    protected function setUpDatabase(): void
    {
        $this->connection = $this->getConnection();

        // Wire our $debugStack to the ORM connection's debug stack so that
        // query assertions (assertTableQueried, assertQueryCount, etc.) reflect
        // queries executed through the EntityManager.
        $driver = $this->connection->getDriver();
        if ($driver instanceof \Modufolio\Appkit\Doctrine\Middleware\Debug\Driver) {
            $this->debugStack = $driver->getDebugStack();
        }

        $this->resetTracking();
        $this->syncQueryTracking();
    }

    /**
     * Route $this->loadFixtures() to the ORM-based parent implementation so
     * that the trait's DBAL fixture loading does not shadow it.
     */
    protected function loadFixtures(): void
    {
        parent::loadFixtures();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        // Initialise the DBAL connection and reset query tracking.
        // Schema already exists on the ORM connection via refreshDatabase().
        $this->setUpDatabase();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => [
                        'path' => '/logout',
                        'target' => '/login',
                    ],
                ],
            ],
        ]);
    }

    public function getTestSchema(): Schema
    {
        $schema = new Schema();

        $usersTable = $schema->createTable('users');
        $usersTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $usersTable->addColumn('email', 'string', ['length' => 180]);
        $usersTable->addColumn('password', 'string', ['length' => 255]);
        $usersTable->addColumn('roles', 'text');
        $usersTable->setPrimaryKey(['id']);
        $usersTable->addUniqueIndex(['email'], 'uniq_users_email');

        $secretsTable = $schema->createTable('user_totp_secrets');
        $secretsTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $secretsTable->addColumn('user_id', 'integer');
        $secretsTable->addColumn('secret', 'text');
        $secretsTable->addColumn('enabled', 'boolean', ['default' => false]);
        $secretsTable->addColumn('confirmed', 'boolean', ['default' => false]);
        $secretsTable->addColumn('enabled_at', 'datetime_immutable', ['notnull' => false]);
        $secretsTable->addColumn('last_used_at', 'datetime_immutable', ['notnull' => false]);
        $secretsTable->addColumn('failed_attempts', 'integer', ['default' => 0]);
        $secretsTable->addColumn('backup_codes', 'text', ['notnull' => false]);
        $secretsTable->addColumn('created_at', 'datetime_immutable', ['notnull' => false]);
        $secretsTable->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $secretsTable->setPrimaryKey(['id']);
        $secretsTable->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        return $schema;
    }

    public function testStatusReturnsFalseWhenNoSecretConfigured(): void
    {
        $this->login();

        $response = $this->get('/api/2fa/status');

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['confirmed']);
    }

    public function testSetupGeneratesSecretAndProvisioningUri(): void
    {
        $this->login();

        $response = $this->post('/api/2fa/setup');

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['secret']);
        $this->assertStringContainsString('otpauth://', $data['provisioning_uri']);

        // Verify the secret was persisted to the database
        $this->assertDatabaseHas('user_totp_secrets', ['enabled' => 0, 'confirmed' => 0]);
        $this->assertDatabaseCount('user_totp_secrets', 1);
    }

    public function testSetupFailsIfAlreadyEnabled(): void
    {
        $this->login();

        // Set up and enable 2FA
        $this->post('/api/2fa/setup');
        $secret = $this->getTotpSecretForUser();
        $code = $this->generateValidCode($secret->getSecret());
        $this->json('POST', '/api/2fa/enable', ['code' => $code]);

        // Try to set up again while enabled
        $response = $this->post('/api/2fa/setup');

        $response->assertStatus(400);
        $data = $response->jsonData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('already has 2FA enabled', $data['error']);
    }

    public function testEnableRequiresCode(): void
    {
        $this->login();
        $this->post('/api/2fa/setup');

        $response = $this->json('POST', '/api/2fa/enable', []);

        $response->assertStatus(400);
        $data = $response->jsonData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('required', $data['error']);
    }

    public function testEnableFailsWithoutSetup(): void
    {
        $this->login();

        $response = $this->json('POST', '/api/2fa/enable', ['code' => '123456']);

        $response->assertStatus(400);
        $data = $response->jsonData();
        $this->assertFalse($data['success']);
    }

    public function testEnableWithValidCodeSucceeds(): void
    {
        $this->login();
        $this->post('/api/2fa/setup');

        $secret = $this->getTotpSecretForUser();
        $code = $this->generateValidCode($secret->getSecret());

        $response = $this->json('POST', '/api/2fa/enable', ['code' => $code]);

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('backup_codes', $data);
        $this->assertCount(10, $data['backup_codes']);

        // Verify the secret is now enabled and confirmed in the database
        $this->assertDatabaseHas('user_totp_secrets', ['enabled' => 1, 'confirmed' => 1]);
        $this->assertTableQueried('user_totp_secrets', 'UPDATE');
    }

    public function testEnableWithInvalidCodeFails(): void
    {
        $this->login();
        $this->post('/api/2fa/setup');

        $response = $this->json('POST', '/api/2fa/enable', ['code' => '000000']);

        $response->assertStatus(400);
        $data = $response->jsonData();
        $this->assertFalse($data['success']);
    }

    public function testStatusReturnsTrueAfterEnable(): void
    {
        $this->login();
        $this->post('/api/2fa/setup');

        $secret = $this->getTotpSecretForUser();
        $code = $this->generateValidCode($secret->getSecret());
        $this->json('POST', '/api/2fa/enable', ['code' => $code]);

        $response = $this->get('/api/2fa/status');

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertTrue($data['enabled']);
        $this->assertTrue($data['confirmed']);
    }

    public function testDisableRemoves2fa(): void
    {
        $this->login();
        $this->post('/api/2fa/setup');

        $secret = $this->getTotpSecretForUser();
        $code = $this->generateValidCode($secret->getSecret());
        $this->json('POST', '/api/2fa/enable', ['code' => $code]);

        $response = $this->post('/api/2fa/disable');

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertTrue($data['success']);

        // Status should now show disabled
        $statusResponse = $this->get('/api/2fa/status');
        $statusData = $statusResponse->jsonData();
        $this->assertFalse($statusData['enabled']);

        // Verify the secret row was removed from the database
        $this->assertDatabaseCount('user_totp_secrets', 0);
        $this->assertDatabaseMissing('user_totp_secrets', ['enabled' => 1]);
    }

    public function testTwoFactorFormRedirectsToLoginWithNoSession(): void
    {
        $response = $this->get('/2fa');

        $response->assertRedirect('/login');
    }

    public function testTwoFactorFormRendersWhenSessionHasToken(): void
    {
        $user = $this->loadUserFromFixture();
        $this->seedTwoFactorToken($user);

        $response = $this->get('/2fa');

        $response->assertStatus(200);
        $data = $response->jsonData();
        $this->assertArrayHasKey('csrf_token', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertSame('johndoe@example.com', $data['email']);
    }

    public function testTwoFactorPostRedirectsToLoginWithNoSession(): void
    {
        $response = $this->post('/2fa', ['totp_code' => '123456']);

        $response->assertRedirect('/login');
    }

    public function testTwoFactorPostWithInvalidCsrfRedirectsToForm(): void
    {
        $user = $this->loadUserFromFixture();
        $this->seedTwoFactorToken($user);

        $response = $this->request('POST', '/2fa', [
            '_csrf_token' => 'invalid-token',
            'totp_code' => '123456',
        ], null, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertRedirect('/2fa');
    }

    public function testTwoFactorPostWithValidCodeCompletesAuthentication(): void
    {
        $user = $this->loadUserFromFixture();

        // Enable 2FA for the user directly via the service
        $totpService = $this->app()->totpService();
        $secret = $totpService->generateSecret($user);
        $code = $this->generateValidCode($secret->getSecret());
        $totpService->enableTwoFactor($secret, $code);

        // Seed a fresh 2FA token in session
        $this->seedTwoFactorToken($user);

        // Get a valid CSRF token via GET /2fa
        $getResponse = $this->get('/2fa');
        $csrfToken = $getResponse->jsonData()['csrf_token'];

        // Generate a fresh valid TOTP code
        $freshCode = $this->generateValidCode($secret->getSecret());

        $response = $this->request('POST', '/2fa', [
            '_csrf_token' => $csrfToken,
            'totp_code' => $freshCode,
        ], null, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertRedirect('/');

        // User should now be fully authenticated
        $token = $this->app()->tokenStorage()->getToken();
        $this->assertNotNull($token);
        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
        $this->assertSame('johndoe@example.com', $token->getUser()->getUserIdentifier());
    }

    public function testTwoFactorPostWithInvalidCodeRedirectsBackToForm(): void
    {
        $user = $this->loadUserFromFixture();

        $totpService = $this->app()->totpService();
        $secret = $totpService->generateSecret($user);
        $enableCode = $this->generateValidCode($secret->getSecret());
        $totpService->enableTwoFactor($secret, $enableCode);

        $this->seedTwoFactorToken($user);

        $getResponse = $this->get('/2fa');
        $csrfToken = $getResponse->jsonData()['csrf_token'];

        $response = $this->request('POST', '/2fa', [
            '_csrf_token' => $csrfToken,
            'totp_code' => '000000',
        ], null, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertRedirect('/2fa');
    }

    public function testTwoFactorPostWithValidBackupCodeCompletesAuthentication(): void
    {
        $user = $this->loadUserFromFixture();

        $totpService = $this->app()->totpService();
        $secret = $totpService->generateSecret($user);
        $code = $this->generateValidCode($secret->getSecret());
        $totpService->enableTwoFactor($secret, $code);

        // Get a backup code from the plain backup codes stored after enable
        $backupCode = $secret->plainBackupCodes[0];

        $this->seedTwoFactorToken($user);

        $getResponse = $this->get('/2fa');
        $csrfToken = $getResponse->jsonData()['csrf_token'];

        $response = $this->request('POST', '/2fa', [
            '_csrf_token' => $csrfToken,
            'backup_code' => $backupCode,
        ], null, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertRedirect('/');

        $token = $this->app()->tokenStorage()->getToken();
        $this->assertNotNull($token);
        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
    }

    public function testCancelTwoFactorRedirectsToLogin(): void
    {
        $user = $this->loadUserFromFixture();
        $this->seedTwoFactorToken($user);

        $response = $this->get('/2fa/cancel');

        $response->assertRedirect('/login');
        $this->assertFalse($this->app()->session()->has('_2fa_token'));
    }

    public function testCancelTwoFactorWithNoSessionStillRedirects(): void
    {
        // /2fa/cancel without _2fa_token in session — AppSecurity won't pass
        // isEntryPointPage, so it goes through auth and redirects to /login
        $response = $this->get('/2fa/cancel');

        $response->assertRedirect('/login');
    }

    public function testLoginWith2faEnabledRedirectsTo2faForm(): void
    {
        // Configure the authenticator with totpService
        $totpService = $this->app()->totpService();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login_2fa'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                ],
            ],
        ]);

        // Register a custom authenticator with totpService
        $this->app()->registerAuthenticator('form_login_2fa', function ($container) use ($totpService) {
            return new FormLoginAuthenticator(
                $container->get(UserRepository::class),
                $container->get(BruteForceProtectionInterface::class),
                $container->get(CsrfTokenManagerInterface::class),
                $container->get(SessionInterface::class),
                $totpService,
                null,
                [
                    'username_parameter' => 'email',
                    'password_parameter' => 'password',
                    'csrf_parameter' => '_csrf_token',
                    'csrf_token_id' => 'authenticate',
                ]
            );
        });

        // Enable 2FA for the fixture user
        $user = $this->loadUserFromFixture();
        $secret = $totpService->generateSecret($user);
        $code = $this->generateValidCode($secret->getSecret());
        $totpService->enableTwoFactor($secret, $code);

        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $response = $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ]);

        $response->assertRedirect('/2fa');
        $this->assertTrue($this->app()->session()->has('_2fa_token'));
    }

    /**
     * Load the primary fixture user (johndoe@example.com).
     */
    private function loadUserFromFixture(): User
    {
        $em = $this->app()->entityManager();
        $user = $em->getRepository(User::class)
            ->findOneBy(['email' => 'johndoe@example.com']);

        $this->assertNotNull($user, 'Fixture user not found.');

        return $user;
    }

    /**
     * Seed a TwoFactorToken into the session to simulate a partial auth state.
     */
    private function seedTwoFactorToken(UserInterface $user): void
    {
        // We need an initialised session — make a dummy request first if needed
        if (!$this->app()->getState()) {
            $this->get('/login');
        }

        $twoFactorToken = new TwoFactorToken($user, 'main', $user->getRoles());
        $this->app()->session()->set('_2fa_token', serialize($twoFactorToken));
    }

    /**
     * Retrieve the persisted TotpSecret entity for the currently logged-in user.
     */
    private function getTotpSecretForUser(): TwoFactorSecret
    {
        $token = $this->app()->tokenStorage()->getToken();
        $this->assertNotNull($token, 'Not authenticated.');

        $user = $token->getUser();
        $secret = $this->app()->totpService()->getTotpSecret($user);
        $this->assertNotNull($secret, 'No TOTP secret found for user.');

        return $secret;
    }

    /**
     * Generate a currently-valid TOTP code for the given base32 secret.
     */
    private function generateValidCode(string $base32Secret): string
    {
        $totp = TOTP::createFromSecret($base32Secret);
        return $totp->now();
    }
}
