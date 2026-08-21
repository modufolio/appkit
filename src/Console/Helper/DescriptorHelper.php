<?php

namespace Modufolio\Appkit\Console\Helper;

use Symfony\Component\Console\Helper\DescriptorHelper as BaseDescriptorHelper;

/**
 * @author    Jean-François Simon <jeanfrancois.simon@sensiolabs.com>
 *
 * @see       https://github.com/symfony/framework-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
class DescriptorHelper extends BaseDescriptorHelper
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this
            ->register('txt', new TextDescriptor())
        ;
    }
}
