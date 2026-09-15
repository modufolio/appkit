<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

/**
 * How much of the firewall's `idle_timeout` is left on the current session.
 *
 * Injectable into a controller, so a "how long have I got left?" endpoint can
 * be written without reaching for the kernel:
 *
 *     public function status(SessionIdleStatus $status): ResponseInterface
 *     {
 *         return Response::json(['remaining' => $status->secondsRemaining]);
 *     }
 *
 * Declare that endpoint's path in the firewall's `idle_ignore_paths`. Without
 * it, a browser polling this endpoint renews the very deadline it reports and
 * the session never times out.
 *
 * `secondsRemaining` is null when the firewall sets no `idle_timeout`, or when
 * nothing is signed in; never negative.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class SessionIdleStatus
{
    /**
     * Session key prefix holding the idle deadline (a unix timestamp), one per
     * firewall so two firewalls sharing a session context expire separately.
     */
    public const string DEADLINE_KEY = '_idle_deadline_';

    /**
     * Flashed as `info` when a session is terminated for inactivity, so the
     * login page can tell the visitor why they are looking at it.
     */
    public const string TIMEOUT_MESSAGE = 'Signed out after a period of inactivity.';

    public function __construct(
        public ?int $secondsRemaining = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->secondsRemaining;
    }
}
