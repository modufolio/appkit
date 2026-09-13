<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

use Modufolio\Appkit\Exception\NotFoundException;
use Psr\Container\ContainerInterface;

/**
 * A fixed set of services, each built on first use.
 *
 * Handed to a collaborator in place of the kernel so it can reach the services
 * it declared and nothing else — the kernel itself is not injectable
 * ({@see \Modufolio\Appkit\Core\AppContainer::resolve()}). Building is deferred
 * because a caller rarely uses every service it declares, and some of them
 * cost: asking for the flash bag starts the session, which puts a cookie on a
 * response that could otherwise be cached.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ServiceLocator implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * @param array<string, \Closure(): mixed> $factories
     */
    public function __construct(private readonly array $factories)
    {
    }

    /**
     * @throws NotFoundException when the id was not declared
     */
    public function get(string $id): mixed
    {
        if (!isset($this->factories[$id])) {
            throw new NotFoundException(sprintf(
                'Service "%s" was not declared here. Declared: %s.',
                $id,
                implode(', ', array_keys($this->factories)) ?: 'none'
            ));
        }

        // array_key_exists, not ??=: a factory is allowed to return null.
        if (!\array_key_exists($id, $this->resolved)) {
            $this->resolved[$id] = ($this->factories[$id])();
        }

        return $this->resolved[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
