<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Event;

use Modufolio\Appkit\Event\Http\UploadStoredEvent;
use Modufolio\Appkit\Event\Security\TwoFactorDisabledEvent;
use Modufolio\Appkit\Event\Security\TwoFactorEnabledEvent;
use Modufolio\Appkit\Http\Upload;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorSecret;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\Factory\Psr17Factory;
use OTPHP\TOTP;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The two services that dispatch on their own, outside the kernel's flow:
 * an upload once it is on disk, and two-factor once its state is flushed.
 */
class UploadAndTwoFactorEventsTest extends AppTestCase
{
    use ClockSensitiveTrait;

    private RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->events = RecordingEventDispatcher::instance();
        $this->events->clear();
    }

    public function tearDown(): void
    {
        $this->events->clear();

        parent::tearDown();
    }

    public function testAStoredUploadIsAnnouncedWithItsFinalPathAndSniffedType(): void
    {
        $tmpDir = sys_get_temp_dir().'/upload_event_'.uniqid();
        $file = (new Psr17Factory())->createUploadedFile(
            (new Psr17Factory())->createStream('plain text content'),
            18,
            UPLOAD_ERR_OK,
            'notes.txt',
            'text/plain',
        );

        try {
            Upload::from($file, $this->events)->saveTo($tmpDir);

            $stored = $this->events->of(UploadStoredEvent::class);
            $this->assertCount(1, $stored);
            $this->assertSame($tmpDir.'/notes.txt', $stored[0]->path);
            $this->assertSame('notes.txt', $stored[0]->filename);
            $this->assertSame('notes.txt', $stored[0]->clientFilename);
            $this->assertSame(18, $stored[0]->size);
            $this->assertSame('text/plain', $stored[0]->mimeType);
        } finally {
            if (file_exists($tmpDir.'/notes.txt')) {
                unlink($tmpDir.'/notes.txt');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testARefusedUploadAnnouncesNothing(): void
    {
        $tmpDir = sys_get_temp_dir().'/upload_event_'.uniqid();
        $file = (new Psr17Factory())->createUploadedFile(
            (new Psr17Factory())->createStream('<?php'),
            5,
            UPLOAD_ERR_OK,
            'shell.php',
        );

        try {
            Upload::from($file)->notifying($this->events)->saveTo($tmpDir);
            $this->fail('Expected the executable extension to be refused');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $this->events->of(UploadStoredEvent::class));
        } finally {
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testEnablingAndDisablingTwoFactorAreAnnouncedAfterTheFlush(): void
    {
        self::mockTime('2024-01-01 00:00:00');
        $service = $this->app()->totpService();
        $user = $this->fixtureUser();

        $secret = $service->generateSecret($user);
        $this->assertSame([], $this->events->of(TwoFactorEnabledEvent::class), 'Generating a secret is not enabling');

        $this->assertTrue($service->enableTwoFactor($secret, $this->codeFor($secret)));

        $enabled = $this->events->of(TwoFactorEnabledEvent::class);
        $this->assertCount(1, $enabled);
        $this->assertSame($user->getUserIdentifier(), $enabled[0]->userIdentifier);

        $service->disableTwoFactor($user);

        $disabled = $this->events->of(TwoFactorDisabledEvent::class);
        $this->assertCount(1, $disabled);
        $this->assertSame($user->getUserIdentifier(), $disabled[0]->userIdentifier);

        // Nothing to disable, nothing to say.
        $service->disableTwoFactor($user);
        $this->assertCount(1, $this->events->of(TwoFactorDisabledEvent::class));
    }

    public function testAWrongCodeEnablesNothingAndAnnouncesNothing(): void
    {
        self::mockTime('2024-01-01 00:00:00');
        $service = $this->app()->totpService();
        $secret = $service->generateSecret($this->fixtureUser());

        $this->assertFalse($service->enableTwoFactor($secret, '000000'));
        $this->assertSame([], $this->events->of(TwoFactorEnabledEvent::class));
    }

    private function codeFor(TwoFactorSecret $secret): string
    {
        $now = Clock::get()->now()->getTimestamp();
        $secretValue = $secret->getSecret();

        if ('' === $secretValue) {
            self::fail('TOTP secret must not be empty');
        }

        return TOTP::createFromSecret($secretValue)->at(max(0, $now));
    }

    private function fixtureUser(): User
    {
        $user = $this->app()->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => 'johndoe@example.com']);

        $this->assertNotNull($user, 'Fixture user not found.');

        return $user;
    }
}
