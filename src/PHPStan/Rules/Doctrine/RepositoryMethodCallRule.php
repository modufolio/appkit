<?php

declare(strict_types=1);

namespace Modufolio\Appkit\PHPStan\Rules\Doctrine;

use Doctrine\Persistence\ObjectRepository;
use Modufolio\Appkit\PHPStan\Doctrine\ObjectMetadataResolver;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * Flags findBy()/findOneBy()/count() calls whose criteria array references a
 * field or association that doesn't exist on the repository's entity.
 *
 * The magic findByX()/findOneByX()/countByX() equivalents resolved via
 * EntityRepository::__call() are covered separately by
 * {@see \Modufolio\Appkit\PHPStan\Reflection\Doctrine\EntityRepositoryClassReflectionExtension},
 * which makes PHPStan aware of those methods in the first place.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class RepositoryMethodCallRule implements Rule
{
    private const CHECKED_METHODS = ['findBy', 'findOneBy', 'count'];

    public function __construct(
        private readonly ObjectMetadataResolver $objectMetadataResolver,
    ) {
    }

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        if (!\in_array($methodName, self::CHECKED_METHODS, true)) {
            return [];
        }

        if (!isset($node->getArgs()[0])) {
            return [];
        }

        $calledOnType = $scope->getType($node->var);
        $entityClassType = $calledOnType->getTemplateType(ObjectRepository::class, 'T');

        /** @var list<class-string> $entityClassNames */
        $entityClassNames = $entityClassType->getObjectClassNames();

        if (1 !== \count($entityClassNames)) {
            return [];
        }

        $classMetadata = $this->objectMetadataResolver->getClassMetadata($entityClassNames[0]);

        if (null === $classMetadata) {
            return [];
        }

        $argType = $scope->getType($node->getArgs()[0]->value);

        $messages = [];

        foreach ($argType->getConstantArrays() as $constantArray) {
            foreach ($constantArray->getKeyTypes() as $keyType) {
                foreach ($keyType->getConstantStrings() as $fieldName) {
                    $field = $fieldName->getValue();

                    if ($classMetadata->hasField($field) || $classMetadata->hasAssociation($field)) {
                        continue;
                    }

                    $messages[] = RuleErrorBuilder::message(sprintf(
                        'Call to method %s::%s() - entity %s does not have a field named $%s.',
                        $calledOnType->describe(VerbosityLevel::typeOnly()),
                        $methodName,
                        $entityClassNames[0],
                        $field,
                    ))->identifier(sprintf('appkit.doctrine.%sArgument', $methodName))->build();
                }
            }
        }

        return $messages;
    }
}
