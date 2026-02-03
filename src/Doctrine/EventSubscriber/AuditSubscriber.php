<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Doctrine\EventSubscriber;

use App\Entity\AuditLog;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

class AuditSubscriber implements EventSubscriber
{
    private array $logs = [];

    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Handle inserted entities
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            $this->record($em, $entity, 'insert', $changes);
        }

        // Handle updated entities
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            $this->record($em, $entity, 'update', $changes);
        }

        // Handle deleted entities
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $id = $this->getEntityId($em, $entity);
            $this->logs[] = new AuditLog(
                entity: get_class($entity),
                entityId: $id,
                action: 'delete',
                changes: null,
                userId: $this->getUserId()
            );
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->logs)) {
            return;
        }

        $em = $args->getObjectManager();

        foreach ($this->logs as $log) {
            $em->persist($log);
        }

        $this->logs = [];
        $em->flush();
    }

    private function record(EntityManagerInterface $em, object $entity, string $action, array $changes): void
    {
        // Skip auditing the audit table itself
        if ($entity instanceof AuditLog) {
            return;
        }

        $id = $this->getEntityId($em, $entity);

        // Filter out sensitive fields from changes (e.g., password)
        $filteredChanges = $this->filterSensitiveData($changes);

        $this->logs[] = new AuditLog(
            entity: get_class($entity),
            entityId: $id,
            action: $action,
            changes: $filteredChanges,
            userId: $this->getUserId()
        );
    }

    private function getEntityId(EntityManagerInterface $em, object $entity): string
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $identifiers = $meta->getIdentifierValues($entity);

        if (empty($identifiers)) {
            return 'unknown';
        }

        return (string) $identifiers[array_key_first($identifiers)];
    }

    private function getUserId(): ?int
    {
        $token = $this->tokenStorage->getToken();

        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return null;
        }

        // Assuming UserInterface has getId method
        return $user->getId();
    }

    /**
     * Filter out sensitive fields like passwords from audit logs
     */
    private function filterSensitiveData(array $changes): array
    {
        $sensitiveFields = ['password', 'plainPassword', 'salt'];

        foreach ($sensitiveFields as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = ['***REDACTED***', '***REDACTED***'];
            }
        }

        return $changes;
    }
}
