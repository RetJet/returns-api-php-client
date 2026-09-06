---
name: Bug report
about: Something in the SDK does not behave as documented
title: ''
labels: bug
assignees: ''
---

**Do not include your API key, or any customer data (e-mail, address, bank account) from an
`RmaRequest`, in this report.** If the bug is a security issue - something that could expose a
key or personal data - use [Security Advisories](https://github.com/RetJet/returns-api-php-client/security/advisories/new)
instead; see [SECURITY.md](../../SECURITY.md).

## Versions

- `retjet/returns-api-php-client`: <!-- composer show retjet/returns-api-php-client -->
- PHP:
- PSR-18 client in use (Guzzle, Symfony HttpClient, curl-client, other):

## What happened

<!-- What you called, what you expected, what you got instead. Include the exception class and
message if one was thrown - method()/path() on ApiException, or the redacted message from
TransportException, is enough; no need to paste a stack trace with request bodies. -->

## Minimal reproduction

```php
// The smallest snippet that reproduces it, ideally against a dev/sandbox key rather than
// production.
```
