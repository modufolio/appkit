<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Bare;

use Modufolio\Appkit\Module\AbstractModule;

/**
 * Fixture module with no config, no services file and no conventional
 * directories — every convention must degrade to an empty contribution.
 */
final class BareModule extends AbstractModule
{
}
