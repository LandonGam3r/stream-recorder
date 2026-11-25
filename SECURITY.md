# Security policy

## Reporting a problem

Please report security problems privately. Use **Security → Report a vulnerability** on this repository; the report then stays between you and the maintainer until it's fixed.

Include what you found, how to reproduce it, and what an attacker could do with it. You'll get a reply within a week. Please don't open a public issue for a security problem.

## Supported versions

Only the latest release gets security fixes. Update by replacing the files listed in [docs/install-webserver.md](docs/install-webserver.md#updating); your settings, streamers, recordings and keys stay as they are.

## How the application protects itself

[docs/security.md](docs/security.md) describes the protections: the dashboard login, the action tokens and the security policy. It also covers the encrypted tokens, the pinned speech library and what is deliberately left as it is.
