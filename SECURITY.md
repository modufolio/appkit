# Security Policy

AppKit is a security-focused framework, and reports about its security are taken seriously.

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.**

Report it privately via GitHub's vulnerability reporting: on the repository page, go to **Security → Report a vulnerability** ([direct link](https://github.com/modufolio/appkit/security/advisories/new)). This opens a private advisory that only the maintainer can see.

Please include:

- The affected component (e.g. an authenticator, the CSRF layer, the emitter) and version or commit.
- A proof of concept or reproduction steps — a failing request, a config that opens the hole, a test case.
- Your assessment of the impact (what an attacker gains, what preconditions they need).

You will get an initial response within a few days. Once a fix is released, the advisory is published with credit to the reporter unless you prefer otherwise.

## Supported versions

Security fixes land on the latest release line. Older minor versions are not patched — upgrade to the latest tag.

## Scope

In scope: everything under `src/`, including the security layer (firewalls, authenticators, CSRF, TOTP, remember-me, brute-force protection, token handling), the emitter path, uploads, and the toolkit's filename/escaping helpers.

Out of scope: vulnerabilities in applications built with AppKit (report to that application), and vulnerabilities in dependencies (report upstream — but if AppKit's *usage* of a dependency is what creates the exposure, that is in scope here).
