<?php

declare(strict_types=1);

namespace Modufolio\Appkit\PHPStan\Reflection\Doctrine;

use Doctrine\Persistence\ObjectRepository;
use Modufolio\Appkit\PHPStan\Doctrine\ObjectMetadataResolver;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ArrayType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\TypeCombinator;

/**
 * Makes PHPStan aware of Doctrine's magic findByX()/findOneByX()/countByX()
 * repository methods (resolved at runtime via EntityRepository::__call()),
 * validating that X is a real field or association on the entity and giving
 * the call a correct return type.
 */
final class EntityRepositoryClassReflectionExtension implements MethodsClassReflectionExtension
{
    public function __construct(
        private readonly ObjectMetadataResolver $objectMetadataResolver,
    ) {
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        $fieldName = $this->resolveFieldName($methodName);

        if (null === $fieldName) {
            return false;
        }

        $entityClassNames = $this->getEntityClassNames($classReflection);

        foreach ($entityClassNames as $entityClassName) {
            $classMetadata = $this->objectMetadataResolver->getClassMetadata($entityClassName);

            if (null === $classMetadata) {
                continue;
            }

            if ($classMetadata->hasField($fieldName) || $classMetadata->hasAssociation($fieldName)) {
                return true;
            }
        }

        return false;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $repositoryAncestor = $classReflection->getAncestorWithClassName(ObjectRepository::class);

        if (null === $repositoryAncestor) {
            throw new ShouldNotHappenException();
        }

        $entityClassType = $repositoryAncestor->getActiveTemplateTypeMap()->getType('T');

        if (null === $entityClassType) {
            throw new ShouldNotHappenException();
        }

        if (str_starts_with($methodName, 'findBy')) {
            $returnType = new ArrayType(new IntegerType(), $entityClassType);
        } elseif (str_starts_with($methodName, 'findOneBy')) {
            $returnType = TypeCombinator::addNull($entityClassType);
        } elseif (str_starts_with($methodName, 'countBy')) {
            $returnType = new IntegerType();
        } else {
            throw new ShouldNotHappenException();
        }

        return new MagicRepositoryMethodReflection($repositoryAncestor, $methodName, $returnType);
    }

    /**
     * Extracts the entity field name from a magic method name, e.g.
     * "findOneByUser" -> "user", "countByEmailAddress" -> "emailAddress".
     * Mirrors EntityRepository::resolveMagicCall()'s own inflection.
     */
    private function resolveFieldName(string $methodName): ?string
    {
        foreach (['findOneBy', 'findBy', 'countBy'] as $prefix) {
            if (str_starts_with($methodName, $prefix) && \strlen($methodName) > \strlen($prefix)) {
                $by = substr($methodName, \strlen($prefix));

                return lcfirst(str_replace([' ', '_', '-'], '', ucwords($by, ' _-')));
            }
        }

        return null;
    }

    /**
     * @return list<class-string>
     */
    private function getEntityClassNames(ClassReflection $classReflection): array
    {
        $repositoryAncestor = $classReflection->getAncestorWithClassName(ObjectRepository::class);

        if (null === $repositoryAncestor) {
            return [];
        }

        $entityClassType = $repositoryAncestor->getActiveTemplateTypeMap()->getType('T');

        if (null === $entityClassType) {
            return [];
        }

        /* @var list<class-string> */
        return $entityClassType->getObjectClassNames();
    }
}
