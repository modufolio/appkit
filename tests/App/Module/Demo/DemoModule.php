<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\DependencyInjection\Symfony\ContainerBuilderAwareInterface;
use Modufolio\Appkit\Module\AbstractModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection\CollectGreetingsPass;
use Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection\GreetingRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fixture module exercising every convention: default config, a
 * config/services.php file, the loadServices() hook, path discovery, the
 * reset() lifecycle — and the optional Symfony build() hook, next to the
 * config/container.php the factory loads on its own.
 */
final class DemoModule extends AbstractModule implements ContainerBuilderAwareInterface
{
    public static int $resets = 0;

    protected function defaultConfig(): array
    {
        return ['per_page' => 10, 'flavor' => 'plain'];
    }

    protected function loadServices(ServiceConfigurator $services, array $config): void
    {
        $services->set(DemoCounter::class, fn () => new DemoCounter($config['per_page']));
    }

    public function build(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('module.demo.built_with', $config['flavor']);

        // A tag this module owns, collected at compile time from wherever a
        // service was declared — the cross-boundary case a bundle exists for.
        $container->register(GreetingRegistry::class, GreetingRegistry::class)->setPublic(true);
        $container->addCompilerPass(new CollectGreetingsPass());
    }

    public function reset(): void
    {
        ++self::$resets;
    }
}
