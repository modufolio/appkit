<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Util;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class ClassDetails
{
    public function __construct(
        /** @var class-string */
        private string $fullClassName,
    ) {
    }

    /**
     * Get list of property names except "id" for use in a make:form context.
     *
     * @return array<string, null>
     */
    public function getFormFields(): array
    {
        $properties = $this->getProperties();

        $fields = array_diff($properties, ['id']);

        $fieldsWithTypes = [];
        foreach ($fields as $field) {
            $fieldsWithTypes[$field] = null;
        }

        return $fieldsWithTypes;
    }

    /**
     * @return list<string>
     */
    private function getProperties(): array
    {
        $reflect = new \ReflectionClass($this->fullClassName);
        $props = $reflect->getProperties();

        $propertiesList = [];

        foreach ($props as $prop) {
            $propertiesList[] = $prop->getName();
        }

        return $propertiesList;
    }

    public function getPath(): string
    {
        $file = (new \ReflectionClass($this->fullClassName))->getFileName();

        if (false === $file) {
            throw new \RuntimeException(sprintf('Class "%s" is not defined in a file.', $this->fullClassName));
        }

        return $file;
    }

    public function hasAttribute(string $attributeClassName): bool
    {
        $reflected = new \ReflectionClass($this->fullClassName);

        foreach ($reflected->getAttributes($attributeClassName) as $reflectedAttribute) {
            if ($reflectedAttribute->getName() === $attributeClassName) {
                return true;
            }
        }

        return false;
    }
}
