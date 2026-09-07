<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

/**
 * The headers of the Inertia protocol, as the client sends and reads them.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class Header
{
    /** Sent by the client on every XHR visit; answered with JSON. */
    public const INERTIA = 'X-Inertia';

    /** The asset version the client was built against. */
    public const VERSION = 'X-Inertia-Version';

    /** Response header of a 409: the URL the client must visit in full. */
    public const LOCATION = 'X-Inertia-Location';

    /** Partial reload: the component the client already shows. */
    public const PARTIAL_COMPONENT = 'X-Inertia-Partial-Component';

    /** Partial reload: the props to send, comma separated. */
    public const PARTIAL_DATA = 'X-Inertia-Partial-Data';

    /** Partial reload: the props to leave out, comma separated. */
    public const PARTIAL_EXCEPT = 'X-Inertia-Partial-Except';

    /** Merge props the client wants replaced rather than merged, comma separated. */
    public const RESET = 'X-Inertia-Reset';

    /** The validation error bag a form asked for. */
    public const ERROR_BAG = 'X-Inertia-Error-Bag';

    /** Once props the client already holds, comma separated; the server leaves them out. */
    public const EXCEPT_ONCE_PROPS = 'X-Inertia-Except-Once-Props';

    /** Infinite scroll: `prepend` or `append`, where the next page goes. */
    public const INFINITE_SCROLL_MERGE_INTENT = 'X-Inertia-Infinite-Scroll-Merge-Intent';

    /** Response header of a 409 that replaces a redirect whose target has a fragment. */
    public const REDIRECT = 'X-Inertia-Redirect';

    private function __construct()
    {
    }
}
