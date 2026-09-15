<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\OAuth;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Security\OAuth\OAuthAccessTokenInterface;
use Modufolio\Appkit\Security\OAuth\OAuthAccessTokenRepositoryInterface;
use Modufolio\Appkit\Security\OAuth\OAuthService;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Refresh-token rotation: presenting a token that was already rotated means
 * two parties hold the same grant, and the whole grant set is revoked.
 */
#[CoversClass(OAuthService::class)]
final class OAuthServiceRefreshReuseTest extends TestCase
{
    public function testAReplayedRefreshTokenRevokesEveryTokenOfTheUser(): void
    {
        $user = new InMemoryUser('victim@example.com', 'x', ['ROLE_USER']);

        $old = $this->createMock(OAuthAccessTokenInterface::class);
        $old->method('getClientId')->willReturn('client-1');
        $old->method('isRefreshTokenExpired')->willReturn(false);
        $old->method('isRevoked')->willReturn(true);
        $old->method('getUser')->willReturn($user);

        $repository = $this->createMock(OAuthAccessTokenRepositoryInterface::class);
        $repository->method('findByRefreshToken')->willReturn($old);
        $repository->expects($this->once())->method('revokeAllForUser')->with($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $service = new OAuthService($entityManager, $repository, OAuthAccessTokenInterface::class);

        $this->assertNull($service->refreshAccessToken('stolen-and-already-rotated', 'client-1'));
    }

    public function testAnUnknownRefreshTokenRevokesNothing(): void
    {
        $repository = $this->createMock(OAuthAccessTokenRepositoryInterface::class);
        $repository->method('findByRefreshToken')->willReturn(null);
        $repository->expects($this->never())->method('revokeAllForUser');

        $service = new OAuthService($this->createMock(EntityManagerInterface::class), $repository, OAuthAccessTokenInterface::class);

        $this->assertNull($service->refreshAccessToken('never-issued', 'client-1'));
    }
}
