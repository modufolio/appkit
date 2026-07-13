<?php

declare(strict_types=1);

namespace Modufolio\Appkit\PHPStan\Doctrine;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;

/**
 * Resolves Doctrine class metadata for PHPStan rules.
 *
 * Boots a single {@see ObjectManager} from a user-supplied loader script (a
 * plain PHP file that `return`s an {@see ObjectManager} instance) and caches
 * metadata lookups by class name for the lifetime of the analysis run.
 */
final class ObjectMetadataResolver
{
    private ?ObjectManager $objectManager = null;

    /** @var array<class-string, ClassMetadata<object>|null> */
    private array $cache = [];

    public function __construct(
        private readonly ?string $objectManagerLoader,
    ) {
    }

    /**
     * @param class-string $className
     *
     * @return ClassMetadata<object>|null
     */
    public function getClassMetadata(string $className): ?ClassMetadata
    {
        if (\array_key_exists($className, $this->cache)) {
            return $this->cache[$className];
        }

        $objectManager = $this->getObjectManager();

        if (null === $objectManager || !class_exists($className)) {
            return $this->cache[$className] = null;
        }

        if ($objectManager->getMetadataFactory()->isTransient($className)) {
            return $this->cache[$className] = null;
        }

        return $this->cache[$className] = $objectManager->getClassMetadata($className);
    }

    private function getObjectManager(): ?ObjectManager
    {
        if (null !== $this->objectManager) {
            return $this->objectManager;
        }

        if (null === $this->objectManagerLoader) {
            return null;
        }

        $objectManager = (static fn (string $file) => require $file)($this->objectManagerLoader);

        if (!$objectManager instanceof ObjectManager) {
            throw new \RuntimeException(sprintf(
                'Object manager loader "%s" must return an instance of %s, %s given.',
                $this->objectManagerLoader,
                ObjectManager::class,
                get_debug_type($objectManager),
            ));
        }

        return $this->objectManager = $objectManager;
    }
}
