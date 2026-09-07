# The privacy model

This is the document worth reading before the others. Everything else Cairn
does follows from what is described here, including several things that look
like limitations until you see why they exist.

## The problem

To count a visit once rather than five times, an analytics tool needs to tell
one browser from another. Every approach to that is a choice about how long
that ability should last:

- **A cookie** lasts until it is cleared — months or years. It is the most
  accurate option and the reason consent banners exist.
- **A fingerprint** lasts as long as the browser's characteristics do, is
  invisible to the visitor, and cannot be cleared. It is worse than a cookie
  precisely because it cannot be refused.
- **A rotating hash** lasts a fixed, short window and then becomes
  unreconstructable. Less accurate. Cannot be used to follow anybody.

Cairn takes the third, with the window fixed at 24 hours.

## How the hash is built

```
visitor = substr(hmac_sha256(ip | user-agent | domain, salt), 0, 16)
```

Four things go in. Only the output is kept.

**The salt** is 32 bytes from a cryptographic random source, generated lazily
on first use and stored in the cache under `cairn:salt:YYYY-MM-DD` with a TTL
of 48 hours. It is never written to a Cairn table, a config file, an
environment variable, or a log line.

**The IP address** is a parameter to one method and is unset before the entry
is constructed. It never reaches a property, a column, an exception message or
a queue payload. `Divoto\Cairn\Recording\EntryFactory` is the only class in the
package that touches one, which is a deliberate choice so that "does Cairn
store IPs" is a question you can answer by reading a single file.

**The user agent** is used to derive the hash and to classify the device into
coarse buckets — "Chrome", "macOS", "Desktop". The string itself is never
stored, and neither is a version number.

**The domain** scopes the hash, so two Cairn installations sharing a cache
cannot correlate the same person across two sites.

## What rotation actually guarantees

At midnight UTC a new salt is generated. The previous day's salt lives for
another 24 hours only so that sessions open across the boundary can be closed,
and it is never used to record a new entry.

Once it expires, **the previous day's hashes cannot be recomputed by anyone**.
Not by an attacker with the database. Not by somebody with the database and the
IP addresses. Not by Cairn. The input that produced them no longer exists.

This means:

- A visitor's activity on Monday and on Tuesday are two unrelated sets of rows,
  with no key that joins them.
- A subject access request can only reach at most one day of activity, and only
  if the person can supply the identifier. `cairn:forget` says this outright
  rather than implying it did more.
- Unique visitor counts over a range are the **sum of daily counts**. Somebody
  who visited on three days counts three times. Cairn marks such numbers with
  `~` rather than presenting them as exact.

That last point is the one people are surprised by. It is not a rounding error
or a bug — it is the direct arithmetic consequence of not being able to
recognise anybody tomorrow.

### One caveat, stated plainly

The guarantee is only as strong as the cache's forgetfulness. A `file` or
`database` cache store writes the salt to disk, where a backup taken today may
still hold it next week. Use a memory-backed store — Redis, Memcached, APCu —
and set `cairn.cache_store` to point at it. `cairn:doctor` reports when you
have not.

## What is stored

Per entry: when, what type, the rotating visitor and session hashes, the route
name, the path (query string stripped except UTM parameters), the referrer's
**host only**, the acquisition channel, campaign parameters, a country code,
coarse device/browser/OS enums, a language tag, an HTTP status, and a server
duration.

A full referring URL is never stored. It can carry a search query, a session
token, or the title of a private document.

With the optional JavaScript beacon enabled, an entry may also carry what the
server cannot see about a page render. The beacon sends exactly seven fields
and nothing else:

| Field | Stored as |
| --- | --- |
| The path it was on | matched against a pageview the server already recorded |
| Seconds on the page | `time_on_page` |
| Deepest scroll, as a percentage | `scroll_depth` |
| Viewport width in CSS pixels | bucketed to one of four `screen_class` values; the width itself is discarded |
| Largest Contentful Paint, ms | `lcp_ms` |
| Interaction to Next Paint, ms | `inp_ms` |
| Cumulative Layout Shift | `cls_milli`, the ratio times a thousand |

These are timings of one page render. They add no identifying signal, and the
Core Web Vitals in particular were already being measured by the browser
whether or not anything collected them. The beacon reads no cookies, writes
none, and collects nothing — device memory, CPU count, installed fonts, canvas
signatures — whose only use is fingerprinting.

## What is never stored

- IP addresses, in any form, anywhere.
- User agent strings.
- Full referring URLs.
- Anything on the visitor's device, unless they opt out (see below).
- A user ID, unless `privacy.track_user_id` is explicitly enabled.

## The one cookie

If a visitor opts out, Cairn stores a cookie containing the character `1`.

The irony is deliberate. The identifier Cairn would otherwise use regenerates
every 24 hours, so a refusal recorded server-side would silently expire at
midnight and the person who said no would be measured again the next day.
Honouring a refusal requires remembering it on the only thing that persists.

The cookie holds no identifier, cannot be used to recognise anybody, and is
readable by the site's own scripts so a consent banner can drive it.

## Signals honoured automatically

- `DNT: 1` — nothing is recorded.
- `Sec-GPC: 1` — nothing is recorded.
- The Cairn opt-out cookie.
- A configured `ConsentResolver`, which is asked before anything else and
  treated as refusing if it throws.

These are checked **before** bot detection, ignore rules and sampling, so no
configuration further down can cause a request to be recorded against a
visitor's expressed wish.

## Aggregates are not personal data

`cairn_aggregates` holds counts — "412 pageviews of /pricing on 14 March". It
contains no visitor hash and nothing that points at an individual, which is why
raw entries default to 30 days while aggregates default to being kept forever.

The dashboard reads aggregates exclusively. That is what makes the short raw
retention window safe: deleting last month's raw rows does not change a single
number on any chart.

## What this model cannot do

- Recognise a returning visitor.
- Report a multi-day journey.
- Attribute a conversion to anything but the last click.
- Deduplicate a visitor across devices.
- Tell you whether your deployment is lawful. That depends on context a package
  cannot see, and nothing here is legal advice.
