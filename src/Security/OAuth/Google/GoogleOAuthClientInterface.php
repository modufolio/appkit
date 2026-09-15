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
     *                      callback; the caller stores it and compares
     */
    /**
     * @param string|null $codeVerifier the PKCE verifier this session keeps for the
     *                                  callback; when given, its S256 challenge is
     *                                  sent so the issued code is bound to it
     */
    public function authorizationUrl(string $state, #[\SensitiveParameter] ?string $codeVerifier = null): string;

    /**
     * Exchange an authorization code for a verified identity.
     *
     * Performs the server-to-server token exchange AND validates the returned
     * ID token (signature against Google's keys, issuer, audience, expiry).
     *
     * @throws GoogleOAuthException on any exchange or verification failure
     */
    /**
     * @param string|null $codeVerifier the verifier the authorization URL was built with
     */
    public function authenticate(string $code, #[\SensitiveParameter] ?string $codeVerifier = null): GoogleIdentity;
}
