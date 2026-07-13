<?php

declare(strict_types=1);

namespace Modufolio\Appkit\PHPStan\Reflection\Doctrine;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\Php\DummyParameter;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Reflection for a single magic findByX()/findOneByX()/countByX() method,
 * resolved via Doctrine's EntityRepository::__call().
 */
final class MagicRepositoryMethodReflection implements MethodReflection
{
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $name,
        private readonly Type $type,
    ) {
    }

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    public function isStatic(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * @return list<FunctionVariant>
     */
    public function getVariants(): array
    {
        if (str_starts_with($this->name, 'findBy')) {
            $arguments = [
                new DummyParameter('argument', new MixedType(), false, null, false, null),
                new DummyParameter('orderBy', new UnionType([new ArrayType(new StringType(), new StringType()), new NullType()]), true, null, false, null),
                new DummyParameter('limit', new UnionType([new IntegerType(), new NullType()]), true, null, false, null),
                new DummyParameter('offset', new UnionType([new IntegerType(), new NullType()]), true, null, false, null),
            ];
        } elseif (str_starts_with($this->name, 'findOneBy')) {
            $arguments = [
                new DummyParameter('argument', new MixedType(), false, null, false, null),
                new DummyParameter('orderBy', new UnionType([new ArrayType(new StringType(), new StringType()), new NullType()]), true, null, false, null),
            ];
        } elseif (str_starts_with($this->name, 'countBy')) {
            $arguments = [
                new DummyParameter('argument', new MixedType(), false, null, false, null),
            ];
        } else {
            throw new ShouldNotHappenException();
        }

        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                $arguments,
                false,
                $this->type,
            ),
        ];
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }
}
