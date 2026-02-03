<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Util\ClassSource\Model;

use Modufolio\Appkit\Console\Str;
use Modufolio\Appkit\Util\UseStatementGenerator;

/**
 * @author Jesse Rushlow <jr@rushlow.dev>
 *
 * @internal
 */
final class ClassData
{
    private function __construct(
        private string $className,
        private string $namespace,
        public readonly ?string $extends,
        public readonly bool $isEntity,
        private UseStatementGenerator $useStatementGenerator,
        private bool $isFinal = true,
        private string $rootNamespace = 'App',
    ) {
    }

    public static function create(string $class, ?string $suffix = null, ?string $extendsClass = null, bool $isEntity = false, array $useStatements = []): self
    {
        $className = Str::getShortClassName($class);

        if (null !== $suffix && !str_ends_with($className, $suffix)) {
            $className = Str::asClassName(\sprintf('%s%s', $className, $suffix));
        }

        $useStatements = new UseStatementGenerator($useStatements);

        if ($extendsClass) {
            $useStatements->addUseStatement($extendsClass);
        }

        return new self(
            className: Str::asClassName($className),
            namespace: Str::getNamespace($class),
            extends: null === $extendsClass ? null : Str::getShortClassName($extendsClass),
            isEntity: $isEntity,
            useStatementGenerator: $useStatements,
        );
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getNamespace(): string
    {
        if (empty($this->namespace)) {
            return $this->rootNamespace;
        }

        return \sprintf('%s\%s', $this->rootNamespace, $this->namespace);
    }

    public function getFullClassName(): string
    {
        return \sprintf('%s\%s', $this->getNamespace(), $this->className);
    }

    public function setRootNamespace(string $rootNamespace): self
    {
        $this->rootNamespace = $rootNamespace;

        return $this;
    }

    public function getClassDeclaration(): string
    {
        $extendsDeclaration = '';

        if (null !== $this->extends) {
            $extendsDeclaration = \sprintf(' extends %s', $this->extends);
        }

        return \sprintf(
            '%sclass %s%s',
            $this->isFinal ? 'final ' : '',
            $this->className,
            $extendsDeclaration,
        );
    }

    public function setIsFinal(bool $isFinal): self
    {
        $this->isFinal = $isFinal;

        return $this;
    }

    public function addUseStatement(array|string $useStatement): self
    {
        $this->useStatementGenerator->addUseStatement($useStatement);

        return $this;
    }

    public function getUseStatements(): string
    {
        return (string)$this->useStatementGenerator;
    }
}
