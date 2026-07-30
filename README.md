# Cairn

**Privacy-first, self-hosted web analytics for Laravel.**

> Every visitor adds a stone. Nobody leaves a name.

Cairn is a single Composer package that gives a Laravel application cookieless
pageview, session and event tracking plus a built-in dashboard — without sending
anything to a third party, and without requiring a cookie-consent banner in its
default configuration.

Because it lives inside your application rather than in a browser tag, Cairn can
report on things an external tool cannot see: route names, Eloquent models, and
authenticated users.

---

> **Status: in development.** This README is a stub. The full documentation —
> installation, the privacy model, the driver matrix, an honest account of what
> cookieless measurement costs you, and a comparison with Matomo and GA4 —
> lands with the first tagged release. Do not depend on this package yet.

---

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- MySQL 8+, MariaDB 10.6+ or PostgreSQL 13+

Redis is optional and always will be. Every Redis-backed capability has a
database-backed driver of equal correctness, so Cairn runs on a shared host with
MySQL and nothing else.

There is no build step. The dashboard ships as Blade with pre-built assets — you
never need npm, Vite or Node to install or use Cairn.

## Installation

```bash
composer require divoto/cairn
```

## Design commitments

These are product-defining, not implementation details:

- **Raw IP addresses are never persisted.** An IP exists in memory only long
  enough to derive a visitor hash and perform a geo lookup.
- **No cookies in the default configuration.** Visitor identity is a salted
  hash. Cairn never writes `Set-Cookie` and never touches the Laravel session.
- **The visitor salt rotates every 24 hours and is never written to disk.**
  Cross-day visitor identity is impossible by construction — and Cairn will not
  add features that reconstruct it.
- **Do Not Track and `Sec-GPC` are honoured by default**, alongside a per-user
  opt-out and a consent-resolver hook.
- **Nothing blocks the response.** Recording happens in a `terminating`
  callback. If Cairn's storage is down, your application still serves requests.
- **Personal-data features are opt-in and loudly documented.** Authenticated
  user attribution and durable-cookie identity are both off by default.

Cairn is described as privacy-first and cookieless by default. It does not claim
to make your deployment compliant with any particular regulation — compliance is
a property of how you deploy and configure software, not of a library, and
nothing in this package or its documentation is legal advice.

## License

MIT. See [LICENSE.md](LICENSE.md).