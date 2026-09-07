<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

/**
 * The props every page carries: the signed-in user, navigation, the messages
 * the server flashed. A host implements it once and hands it to the
 * {@see InertiaRenderer}; a page's own props win over it for the same key.
 *
 * Values may be closures (or {@see Props\Prop} instances): they are computed
 * only when the request actually sends the prop, so a partial reload asking
 * for one prop does not rebuild the navigation.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface SharedPropsInterface
{
    /** @return array<string, mixed> */
    public function create(): array;
}
