# Security policy

**English** · [Polski](docs/pl/SECURITY.md)

This SDK holds an API key on every request and, through `RmaRequest`, handles a customer's
e-mail, postal address and bank account number. Treat anything that could leak either as a
security issue, not a bug report.

## Reporting a vulnerability

Do not open a public GitHub issue for a suspected vulnerability - that publishes the details
before a fix exists.

Instead, use one of:

- [GitHub Security Advisories](https://github.com/RetJet/returns-api-php-client/security/advisories/new)
  for this repository (private by default).
- E-mail [contact@retjet.com](mailto:contact@retjet.com).

Include the affected version, a minimal reproduction, and the impact as you understand it
(what an attacker gains, and what they need to have first). We will acknowledge the report,
work out a fix, and coordinate a disclosure timeline with you before anything is made public.

## Supported versions

Before `v1.0.0`, only the latest tagged release is supported. Once tagged releases exist, this
section will list which major versions still receive security fixes.

## Scope

In scope: this package's own code (`src/`) - credential handling, request/response parsing,
and anything that could expose the API key or the personal data an `RmaRequest` carries.

Out of scope: the RetJet API itself (report those separately to RetJet), and
vulnerabilities in this package's dependencies (report those upstream; open an issue here only
if a dependency vulnerability requires a version bump on our side).

Anything that is not a security issue - a usage question, an ordinary bug, a feature request -
belongs in the [issue tracker](https://github.com/RetJet/returns-api-php-client/issues) or,
if you would rather not write in public, at [support@retjet.com](mailto:support@retjet.com).
Please do not send vulnerabilities there: it is an ordinary support queue, read by more people
and with no confidentiality guarantee.
