<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth\Google;

/**
 * A verified identity from Google, distilled from a validated ID token.
 *
 * Only fields the login flow needs are kept. `emailVerified` is carried
 * explicitly rather than assumed: Google will return an address a user has
 * not proven they own, and an admin panel must not treat that as identity.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        /** Google's stable, unique account id (the `sub` claim). */
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public ?string $name = null,
        /** The Workspace domain (`hd` claim), when the account belongs to one. */
        public ?string $hostedDomain = null,
    ) {
    }
}
