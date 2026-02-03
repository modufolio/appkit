<?php

namespace Modufolio\Appkit\Console\Helper;

use Symfony\Component\Console\Helper\DescriptorHelper as BaseDescriptorHelper;

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
