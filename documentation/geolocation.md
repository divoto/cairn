# Country reporting

Out of the box the Countries panel is empty, and that is deliberate. Cairn
bundles no geo database and will not call a geolocation service per request —
that would send a visitor's address to a third party on every pageview, which
is the thing this package exists not to do.

Enabling it means putting a database on your own disk. The lookup happens
locally and nothing leaves your server.

## Setup

**1. Install the reader.** It is a `suggest`, so it is not there already:

```bash
composer require geoip2/geoip2
```

**2. Get MaxMind credentials.** GeoLite2 is free, but downloading it needs an
account:

- Sign up at [maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup)
- Under **Manage License Keys**, create a key
- Note the **account ID** — it is the number on your account page, not your
  email address. Using the email is the single most common way this goes wrong.

```env
MAXMIND_ACCOUNT_ID=123456
MAXMIND_LICENSE_KEY=your_key_here
```

**3. Download it.**

```bash
php artisan cairn:geoip
```

That fetches GeoLite2-Country, checks it against the sha256 MaxMind publishes,
unpacks it, and installs it at `storage/app/geoip/GeoLite2-Country.mmdb` — which
is where Cairn already looks. It then tells you anything still left to do.

To keep the credentials out of your environment entirely, pass them instead:

```bash
php artisan cairn:geoip --account-id=123456 --key=your_key_here
```

**4. Point Cairn at it.** One line:

```php
// config/cairn.php
'privacy' => [
    'geo_resolver' => \Divoto\Cairn\Geo\MaxMindGeoResolver::class,
],
```

**5. Check it.**

```bash
php artisan cairn:doctor
```

New visits carry a country immediately. Existing entries will not: the address
they came from is long gone, which is rather the point.

> **Testing locally?** You will still see nothing. `127.0.0.1` is a private
> address, and Cairn skips those before any lookup happens. Country data only
> appears for real traffic.

### Doing it by hand

If the server cannot reach MaxMind, or you would rather not put credentials on
it, download **GeoLite2-Country.mmdb** in a browser and move it into place:

```bash
mkdir -p storage/app/geoip
mv ~/Downloads/GeoLite2-Country.mmdb storage/app/geoip/
```

The database path defaults to `storage/app/geoip/GeoLite2-Country.mmdb`.
**Relative paths resolve against your application root**, so that setting is the
same on every machine and can be committed. To keep the database elsewhere —
shared between applications, or on a mounted volume — override it:

```env
CAIRN_GEO_DATABASE=geo/GeoLite2-Country.mmdb     # relative to the app root
CAIRN_GEO_DATABASE=/srv/geoip/GeoLite2-City.mmdb # absolute, used as given
```

## Keeping it current

GeoLite2 is rebuilt on Tuesdays and Fridays, and an old database drifts as
address blocks are reallocated — visitors quietly appear in the wrong country
with nothing to indicate it. Schedule the download:

```php
// routes/console.php
Schedule::command('cairn:geoip')->weeklyOn(3, '04:00');
```

Weekly is plenty. Anything more frequent is wasted, and MaxMind will eventually
rate limit the account.

The command is safe to run repeatedly and safe to run unattended: the existing
database is replaced only once a new one has been downloaded, checksum-verified
and unpacked. A network blip, a rejected key or a truncated download leaves the
working database exactly as it was, and exits non-zero so your scheduler
notices.

`cairn:geoip` is the only part of Cairn that makes a network call, and it only
ever runs from the command line. The lookups themselves are always local.

The resolver opens the database once per process and holds it, so a file
replaced underneath a long-running worker is picked up on its next restart. For
`php artisan serve` or FPM this is a non-issue.

## Going finer than country

`privacy.geo_precision` accepts `none`, `country`, `region` and `city`. Beyond
`country` you also need the larger **GeoLite2-City.mmdb** (~60MB):

```bash
php artisan cairn:geoip --edition=GeoLite2-City
```

That writes `GeoLite2-City.mmdb` alongside the country database rather than over
it, so point `CAIRN_GEO_DATABASE` at the new file once it is there.

Think before you do. Each step narrows the group a visitor hash could belong
to. A country plus a browser plus a device class describes a very large number
of people; a city plus the same three may describe a handful, and on a
low-traffic site possibly one. `cairn:doctor` reports anything above `country`
for that reason.

Cairn enforces the setting rather than trusting the resolver: anything finer
than the configured precision is discarded before an entry is built, so a city
database on a `country` deployment cannot leak a city into a column.

## Writing your own resolver

`geo_resolver` accepts any class implementing the contract, so an IP2Location
database, a corporate lookup service, or a CDN-supplied header all work:

```php
namespace App\Analytics;

use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Data\GeoLocation;

final class CloudflareGeoResolver implements GeoResolver
{
    public function __construct(private \Illuminate\Http\Request $request) {}

    public function resolve(string $ip): ?GeoLocation
    {
        // Cloudflare has already done the lookup at the edge.
        $country = $this->request->header('CF-IPCountry');

        return is_string($country) && strlen($country) === 2
            ? new GeoLocation(country: strtoupper($country))
            : null;
    }
}
```

```php
'geo_resolver' => \App\Analytics\CloudflareGeoResolver::class,
```

Two rules a resolver must obey:

- **Never store, log or return the address it is given.** It exists for the
  duration of the call and no longer. Cairn's own resolver never assigns it to
  a property.
- **Never block the response.** A resolver making a synchronous HTTP call per
  request is a bug rather than a trade-off. If a service is the only option,
  cache aggressively — and note that you are then sending visitor addresses to
  that service, which is a decision to make deliberately.

## What Cairn does around the lookup

Worth knowing, because it means a resolver cannot leak more than intended:

1. The address is **masked before the resolver sees it** — IPv4 to /24, IPv6 to
   /48. Country and usually region survive that; a household does not.
2. Private and loopback ranges are **skipped entirely**, so no lookup happens
   for them at all.
3. Whatever comes back is **reduced to `geo_precision`** before the entry is
   built.
4. The address goes out of scope immediately afterwards. It reaches no column,
   log line, cache entry or queue payload.

## Licensing

GeoLite2 is distributed by MaxMind under its own end user licence agreement,
which has attribution requirements and restrictions on redistribution. Read it
before shipping. Cairn neither bundles nor redistributes any database, and this
paragraph is not legal advice.
