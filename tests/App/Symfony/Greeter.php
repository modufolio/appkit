<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoCounter;

/**
 * A Symfony-autowired service: takes a kernel core service, a module service
 * the kernel declares, and a parameter bound in container.php.
 */
final class Greeter
{
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        public readonly DemoCounter $counter,
        public readonly string $prefix,
    ) {
    }

    public function greet(string $name): string
    {
        return sprintf('%s, %s (%d per page)', $this->prefix, $name, $this->counter->perPage);
    }
}
