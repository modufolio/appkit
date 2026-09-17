<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Tests\App\Entity\LoginAudit;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records a login in the database. Nothing here is registered by hand: the
 * class is picked up by load() in container.php, the EntityManager comes from
 * the bridge by autowiring, and the attribute is the only thing that connects
 * the method to the kernel's event.
 *
 * So a row in login_audit after a login means the whole chain held — compile-
 * time listener wiring, constructor autowiring across the two containers, and
 * a listener built lazily inside the request that dispatched the event.
 */
final class LoginAuditRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[AsEventListener]
    public function onLogin(UserLoggedInEvent $event): void
    {
        $this->entityManager->persist(new LoginAudit($event->userIdentifier, $event->firewallName));
        $this->entityManager->flush();
    }
}
