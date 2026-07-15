<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Core\ResetInterface;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugMiddleware;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class EntityManagerFactory implements ResetInterface
{
    private ?EntityManagerInterface $entityManager = null;
    private ?Connection $connection = null;

    public function __construct(
        private readonly string $baseDir,
        private readonly Environment $environment,
        private readonly \Closure $configuratorFactory,
        private readonly ?DebugStack $debugStack = null,
    ) {
    }

    public function get(): EntityManagerInterface
    {
        if ($this->entityManager && $this->entityManager->isOpen()) {
            return $this->entityManager;
        }

        return $this->entityManager = $this->build();
    }

    public function reset(): void
    {
        // Outcome of profiling and benchmarking with RoadRunner:
        // In a long-lived worker the EntityManager and its
        // DB connection are expensive to rebuild — opening a fresh SQLite
        // connection costs ~100x a query, and rebuilding the EM/metadata churns
        // ~1.8KB per request. So between requests we only detach entities
        // (clear the identity map) and keep the open connection alive.
        // TODO: profile and benchmark with MySQL/PostgreSQL to see if the same holds true.
        if (null !== $this->entityManager && $this->entityManager->isOpen()) {
            $this->entityManager->clear();

            return;
        }

        // The EM was closed (e.g. after a DBAL error). Tear it down fully so the
        // next get() rebuilds a clean instance.
        $this->connection?->close();
        $this->entityManager = null;
        $this->connection = null;
    }

    private function build(): EntityManagerInterface
    {
        $configurator = new OrmConfigurator();
        ($this->configuratorFactory)($configurator);

        $defaultCache = $this->environment->isProd()
            ? new FilesystemAdapter('doctrine', 0, $this->baseDir.'/var/cache')
            : new ArrayAdapter();

        $config = $configurator->ormConfig;
        $config->setMetadataCache($configurator->metadataCache ?? $defaultCache);
        $config->setQueryCache($configurator->queryCache ?? $defaultCache);
        if (null !== $configurator->resultCache) {
            $config->setResultCache($configurator->resultCache);
        }
        $config->setProxyDir($this->baseDir.'/var/proxies');
        $config->setProxyNamespace('DoctrineProxies');
        $config->setAutoGenerateProxyClasses(!$this->environment->isProd());
        $config->setMetadataDriverImpl(new AttributeDriver($configurator->entityPaths));

        if (!$this->environment->isProd() && null !== $this->debugStack) {
            $configurator->dbalConfig->setMiddlewares([
                ...$configurator->dbalConfig->getMiddlewares(),
                new DebugMiddleware($this->debugStack),
            ]);
        }

        $this->connection = DriverManager::getConnection($configurator->connectionParams, $configurator->dbalConfig);

        $em = new EntityManager($this->connection, $config);

        foreach ($configurator->getSubscribers() as $subscriber) {
            $em->getEventManager()->addEventSubscriber($subscriber);
        }

        return $em;
    }
}
