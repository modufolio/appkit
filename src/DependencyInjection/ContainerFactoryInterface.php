<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

use Modufolio\Appkit\Core\Kernel;
use Psr\Container\ContainerInterface;

/**
 * Builds the optional second container that sits behind the kernel.
 *
 * The kernel resolves every id it knows itself — core accessors,
 * config/services.php, module services, repositories — and only then asks the
 * fallback container. A factory registered with
 * {@see Kernel::configureContainer()} produces that fallback during boot(),
 * once the parameter bag and the module parameters exist and before any
 * module's boot() runs, so a module can reach the finished container there.
 *
 * The bundled implementation is
 * {@see Symfony\ContainerFactory}; any
 * PSR-11 container can be adapted the same way.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ContainerFactoryInterface
{
    /**
     * The id under which a fallback container exposes the kernel to its own
     * services, as a PSR-11 container. The kernel hands itself back under
     * this id after a reset that dropped service instances.
     */
    public const KERNEL_ID = 'appkit.kernel';

    /**
     * The tag a module (or container.php) puts on the service that is to be
     * the firewall's user provider, and the id the fallback container then
     * publishes it under for the application's userProvider() to fetch.
     */
    public const USER_PROVIDER_ID = 'appkit.user_provider';

    public function create(Kernel $kernel): ContainerInterface;
}
