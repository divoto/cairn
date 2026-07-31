# Analytics — a template, not legal advice

**Read this before you use it.** This is a starting point describing what Cairn
does by default. It is not legal advice, it has not been reviewed by a lawyer,
and it will be wrong for your site if you have changed Cairn's configuration or
if you use any other analytics, advertising or embedded third-party service.

Edit it. Delete what does not apply. Have somebody qualified check it.

Run `php artisan cairn:doctor` first — it reports which of the paragraphs below
still describe your installation.

---

## What we measure

We measure how this site is used, so we can see which pages people find useful
and which are not working. We do this with [Cairn](https://github.com/divoto/cairn),
which runs on our own servers. No analytics data about your visit is sent to a
third party.

## What we do not store

- **We do not store your IP address.** It exists in our server's memory for the
  moment it takes to work out roughly which country a visit came from, and is
  then discarded. It is never written to a database, a log file, or a backup.
- **We do not set analytics cookies**, and we do not use any other means of
  storing information on your device to recognise you.
- **We do not know who you are.** Nothing we record can be traced back to a
  person.

## How visits are counted without identifying you

To count a visit once rather than five times, we need some way to tell one
browser from another for a short period. We do this by combining your IP
address, your browser's user-agent string and our domain name, then putting
them through a one-way cryptographic function with a secret random value we
call a salt.

The result is a short string of bytes. It cannot be reversed back into your IP
address.

**The salt is replaced with a new random value every 24 hours, and the old one
is destroyed.** Once that happens, the previous day's identifiers cannot be
recomputed by anyone — including us. Your visit today and your visit tomorrow
are unconnectable, permanently and by design.

One consequence worth stating: because of this, we cannot tell returning
visitors from new ones. Our visitor numbers count somebody who came back on
three days as three visitors. We accept the less useful number.

## What we do record

For each page view: the page, the site that linked you here (the domain only —
never the full address, which can contain search terms), your approximate
country, your browser and operating system by name, your device type, your
language, and how long our server took to respond.

## If you would rather not be measured

We honour these automatically — you do not need to ask us:

- **Do Not Track.** If your browser sends `DNT: 1`, we record nothing.
- **Global Privacy Control.** If your browser or extension sends `Sec-GPC: 1`,
  we record nothing.

You can also opt out directly:

<!-- Publish the opt-out route and adjust this to match. -->
<a href="/cairn/opt-out">Opt out of analytics on this site</a>

Opting out stores a single cookie on your device containing the character `1`.
It holds no identifier and cannot be used to recognise you. It exists solely so
we remember your answer — the identifier we would otherwise use is regenerated
daily, so we have nothing else to attach your preference to.

## Your rights

If you believe we hold data about you and want a copy or want it erased,
contact us at **[your contact address]**.

Because our identifiers are regenerated every 24 hours, we can normally only
locate at most one day of activity, and only if you can tell us the identifier.
Everything older is already beyond our ability to connect to you.

## How long we keep it

Individual page-view records are deleted after **[30] days**. What remains
after that is counts — "412 people viewed this page in March" — which contain
nothing about any individual.

## Changes

Last updated: **[date]**.
