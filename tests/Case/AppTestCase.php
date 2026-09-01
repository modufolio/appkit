<?php

namespace Modufolio\Appkit\Tests\Case;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Modufolio\Appkit\Doctrine\EntityFactory;
use Modufolio\Appkit\Testing\AppTestCase as BaseAppTestCase;
use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Tests\App\AppFactory;
use Modufolio\Appkit\Tests\DataFixtures\AppFixtures;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

abstract class AppTestCase extends BaseAppTestCase
{
    private static ?App $app = null;

    protected function app(): App
    {
        if (null === self::$app) {
            self::$app = AppFactory::create(dirname(__DIR__, 2));
            self::$app->initializeConsoleState();
        }

        return self::$app;
    }

    protected function resetAppConfiguration(): void
    {
        // Framework tests reconfigure firewalls per test; start each from none.
        $this->app()->configureFirewall([]);
    }

    protected function loadFixtures(): void
    {
        $serializer = $this->app()->serializer();
        self::assertInstanceOf(DenormalizerInterface::class, $serializer);

        $factory = (new EntityFactory(
            $this->app()->entityManager(),
            $serializer,
            $this->app()->validator()
        ))->loadConfig(require AppFactory::configDir($this->app()->baseDir).'/fixture_factories.php');

        $executor = new ORMExecutor($this->app()->entityManager(), new ORMPurger());
        $executor->execute([new AppFixtures($factory)]);
    }

    /**
     * @throws \JsonException
     */
    protected function login(): void
    {
        $this->actingAs('johndoe@example.com', 'secret');
    }
}
