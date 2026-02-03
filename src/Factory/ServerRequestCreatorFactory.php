<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Factory;

use Modufolio\Psr7\Http\ServerRequestCreator;
use Modufolio\Psr7\Http\ServerRequestCreatorInterface;

class ServerRequestCreatorFactory
{
    public static function create(): ServerRequestCreatorInterface
    {
        $psr17Factory = new Psr17Factory();

        return new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );
    }

}
