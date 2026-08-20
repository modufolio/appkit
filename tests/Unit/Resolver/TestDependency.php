<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

/**
 * Empty type-hint target shared by the TypeHintResolver and
 * TypeHintContainerResolver test cases.
 *
 * It lives in its own file so those test cases can be run individually (and in
 * parallel), rather than relying on another test file having been loaded first.
 */
class TestDependency
{
}
