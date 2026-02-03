<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Exception;

use Symfony\Component\Console\Exception\ExceptionInterface;

final class RuntimeCommandException extends \RuntimeException implements ExceptionInterface
{
}
