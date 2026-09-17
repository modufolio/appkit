<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row per successful login, written by a listener the Symfony container
 * wired. The point of the table is that it can only fill if the container
 * built the listener with a working EntityManager, so the assertion is about
 * the wiring rather than about auditing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'login_audit')]
class LoginAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_identifier', length: 180)]
    private string $userIdentifier;

    #[ORM\Column(name: 'firewall_name', length: 64)]
    private string $firewallName;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    public function __construct(string $userIdentifier, string $firewallName)
    {
        $this->userIdentifier = $userIdentifier;
        $this->firewallName = $firewallName;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
