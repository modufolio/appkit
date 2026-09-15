# Impersonation (switch user)

Impersonation lets an administrator act as another user — for support and
debugging — and then return to their own account. Enable it per firewall:

```php
->firewall('main', [
    'pattern'        => '/panel',
    'authenticators' => ['form_login'],
    'entry_point'    => '/panel/login',
    'switch_user'    => [
        'enabled'   => true,               // required — the section alone does nothing
        'role'      => 'ROLE_SUPER_ADMIN', // the impersonator must hold this
        'parameter' => '_switch_user',     // carries the target identifier
        'target'    => '/panel',           // optional landing path
    ],
])
```

Post `_switch_user=<identifier>` to any path inside the firewall to switch, and
the reserved value `_exit` (`Kernel::SWITCH_USER_EXIT`) to return:

```html
<form method="post" action="/panel">
    <input type="hidden" name="_switch_user" value="support@example.com">
    <input type="hidden" name="_csrf_token" value="<?= $csrfTokenManager->getToken('switch_user')->getValue() ?>">
    <button>View as this user</button>
</form>
```

The kernel then resolves the target user, swaps the session token for a
`SwitchUserToken` wrapping the original, and redirects. `_exit` restores the
original token, re-read from the user provider so roles revoked during the
switch take effect immediately.

`role` is decided through the [role hierarchy](access-control.md#role-hierarchy), so
`'role' => 'ROLE_ADMIN'` also admits `ROLE_SUPER_ADMIN` when the hierarchy maps
it. Switching again while already impersonating exits first rather than
nesting, so `_exit` always lands on the real administrator.

## Why POST, and why a CSRF token

Symfony's `SwitchUserListener` also accepts a plain `?_switch_user=…` link.
AppKit does not: the request must be a POST carrying a valid CSRF token —
either the dedicated `switch_user` token as the `_csrf_token` field, or the
firewall's session token in an `X-CSRF-Token` header, exactly as for
[logout](csrf.md).

A GET link would let `<img src="/panel?_switch_user=victim">` on any
third-party page silently switch an administrator's session into another
account. The impersonator's own identity is the last thing that should be
forgeable cross-site, so this check runs regardless of the firewall's `csrf`
setting.

It also replaces the firewall-wide CSRF check for that one request: a POST
carrying the switch-user parameter is judged by the switch-user token alone,
so the form above needs no second token for the `csrf` id. Every other POST on
the firewall keeps the usual check.

An unknown identifier, a locked account and an insufficient role are all
refused identically (403), so nobody past the role gate can probe which
accounts exist.

## Switching by hand

If the built-in flow does not fit — you route by UUID, render an Inertia
response, or attach your own flash messages — build the token yourself.
Protect the route with `#[IsGranted]` so only authenticated admins can reach
it; the firewall handles unauthenticated users before any switch logic runs, so
a null-token check inside the controller is neither necessary nor appropriate
(it would produce a 500 instead of a proper login redirect).

Whichever role you gate on, also refuse targets more privileged than the
impersonator. Letting a `ROLE_ADMIN` impersonate a `ROLE_SUPER_ADMIN` is a
privilege escalation: they return holding that role for the rest of the
session. The built-in flow avoids this by checking a single role, so grant it
only to accounts that may reach every other account.

The `string $firewall` parameter is injected automatically by the Kernel — it contains the name of the active firewall for the current request. `$this->session` in these examples is a `SessionInterface` injected through the constructor (`AbstractController` does not provide one) — wire it in `config/controllers.php`:

```php
use Modufolio\Appkit\Attributes\IsGranted;
use Modufolio\Appkit\Security\Token\SwitchUserToken;

#[IsGranted('ROLE_ADMIN')]
#[Route(path: '/users/{id}/switch', name: 'users_switch', methods: ['POST'])]
public function switchUser(
    #[MapEntity] User $targetUser,
    string $firewall,
): ResponseInterface {
    // $this->tokenStorage->getToken() is guaranteed non-null here:
    // #[IsGranted] already verified the user is authenticated.
    $currentToken = $this->tokenStorage->getToken();

    $refreshedTarget = $this->userProvider->refreshUser($targetUser);

    $switchToken = new SwitchUserToken(
        user:          $refreshedTarget,
        firewallName:  $firewall,
        roles:         $refreshedTarget->getRoles(),
        originalToken: $currentToken,
    );

    $this->tokenStorage->setToken($switchToken);
    $this->session->set('_security_' . $firewall, serialize($switchToken));

    return Response::redirect($this->urlGenerator->generate('dashboard'));
}
```

## Exiting impersonation

Check that the current token is a `SwitchUserToken`, retrieve the original token with `getOriginalToken()`, and restore it the same way.

```php
use Modufolio\Appkit\Security\Token\SwitchUserToken;

#[Route(path: '/users/switch/exit', name: 'users_switch_exit', methods: ['POST'])]
public function exitSwitchUser(string $firewall): ResponseInterface
{
    $currentToken = $this->tokenStorage->getToken();

    if (!$currentToken instanceof SwitchUserToken) {
        return Response::redirect($this->urlGenerator->generate('dashboard'));
    }

    $originalToken = $currentToken->getOriginalToken();
    $this->tokenStorage->setToken($originalToken);
    $this->session->set('_security_' . $firewall, serialize($originalToken));

    return Response::redirect($this->urlGenerator->generate('dashboard'));
}
```

## Detecting impersonation

`SwitchUserToken` exposes two ways to check whether the current session is impersonating:

```php
use Modufolio\Appkit\Security\Token\SwitchUserToken;

$token = $this->tokenStorage->getToken();

$token instanceof SwitchUserToken;       // true when impersonating
$token->isImpersonating();              // same check via method
$token->getAttribute('ROLE_PREVIOUS_ADMIN'); // true — set as an ATTRIBUTE, not a role
$token->getOriginalToken()->getUser();  // the original admin user
$token->getOriginatedFromUri();         // page the switch started from, or null
```

To gate a route or path on impersonation, use the `IS_IMPERSONATOR`
[trust-level attribute](access-control.md#trust-level-access) rather than checking the token
type by hand — e.g. an "exit impersonation" banner action reachable only while
impersonating.

## `SwitchUserToken` constructor

```php
new SwitchUserToken(
    user:              UserInterface $user,           // the user to impersonate
    firewallName:      string $firewallName,          // must not be empty
    roles:             array $roles,                  // roles for the impersonated session
    originalToken:     TokenInterface $originalToken, // the token to restore on exit
    originatedFromUri: ?string $originatedFromUri = null, // where to return on exit
)
```
