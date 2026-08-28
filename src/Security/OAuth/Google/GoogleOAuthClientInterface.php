<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth\Google;

/**
 * The client half of "Sign in with Google": build the redirect that starts
 * the flow, and turn the code Google sends back into a verified identity.
 *
 * An interface so the authenticator can be exercised without touching the
 * network — a fake returns a {@see GoogleIdentity} or throws.
 */
interface GoogleOAuthClientInterface
{
    /**
     * The URL to send the browser to, beginning the consent flow.
     *
     * @param string $state opaque anti-forgery value echoed back on the
     *                      callback; the caller stores it and compares.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Exchange an authorization code for a verified identity.
     *
     * Performs the server-to-server token exchange AND validates the returned
     * ID token (signature against Google's keys, issuer, audience, expiry).
     *
     * @throws GoogleOAuthException on any exchange or verification failure
     */
    public function authenticate(string $code): GoogleIdentity;
}
