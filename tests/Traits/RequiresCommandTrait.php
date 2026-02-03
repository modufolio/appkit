<?php

namespace Modufolio\Appkit\Tests\Traits;


use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;

trait RequiresCommandTrait
{
    /**
     * @throws \ReflectionException
     */
    protected function checkCommandRequirements(): void
    {
        $reflection = new ReflectionClass($this);

        // Check class-level attribute
        foreach ($reflection->getAttributes(\Modufolio\Appkit\Tests\App\Tests\Attribute\RequiresCommand::class) as $attr) {
            $this->checkCommands($attr->newInstance()->commands, $attr->newInstance()->message);
        }

        // Check method-level attribute if current test is set
        if (property_exists($this, 'testName') && $this->testName) {
            $method = new ReflectionMethod($this, $this->testName);
            foreach ($method->getAttributes(\Modufolio\Appkit\Tests\App\Tests\Attribute\RequiresCommand::class) as $attr) {
                $this->checkCommands($attr->newInstance()->commands, $attr->newInstance()->message);
            }
        }
    }

    private function checkCommands(array $commands, ?string $message): void
    {
        foreach ($commands as $command) {
            $exists = trim((string) shell_exec(sprintf('command -v %s', escapeshellarg($command))));
            if (empty($exists)) {
                Assert::markTestSkipped(
                    $message ?? sprintf('The required command "%s" is not available in PATH.', $command)
                );
            }
        }
    }
}
