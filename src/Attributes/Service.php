<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * Marks an App/Kernel method as injectable through the `'@method'` form in
 * config/controllers.php.
 *
 * The kernel builds an allowlist of annotated methods at boot (by reflection,
 * once per process — nothing is dumped or cached on disk) and refuses any
 * `'@'` reference outside it, at boot time, with the full list of mistakes.
 * Without the attribute, every method on the App — including protected
 * kernel internals — would be one config string away from being invoked.
 *
 * The attribute is honoured on the declaration you annotate: putting it on an
 * abstract kernel method (e.g. userProvider()) covers every App's
 * implementation without re-annotating.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Service
{
}
