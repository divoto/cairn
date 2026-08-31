<?php

declare(strict_types=1);

use Divoto\Cairn\Privacy\IpAnonymiser;

/*
|--------------------------------------------------------------------------
| IP anonymisation
|--------------------------------------------------------------------------
|
| This is defence in depth, not the main protection. The main protection is
| that no address of any kind is ever persisted. This narrows what a geo
| resolver — or a resolver's log — could ever see.
|
*/

function anonymiser(): IpAnonymiser
{
    return new IpAnonymiser;
}

it('drops the final octet of an IPv4 address', function (string $ip, string $expected): void {
    expect(anonymiser()->anonymise($ip))->toBe($expected);
})->with([
    ['203.0.113.42', '203.0.113.0'],
    ['203.0.113.1', '203.0.113.0'],
    ['203.0.113.255', '203.0.113.0'],
    ['8.8.8.8', '8.8.8.0'],
    ['1.2.3.4', '1.2.3.0'],
]);

it('keeps only the first 48 bits of an IPv6 address', function (string $ip, string $expected): void {
    expect(anonymiser()->anonymise($ip))->toBe($expected);
})->with([
    ['2001:db8:1234:5678:9abc:def0:1234:5678', '2001:db8:1234::'],
    ['2001:db8:1234::1', '2001:db8:1234::'],
    ['fe80::1', 'fe80::'],
]);

/**
 * The same address has many textual spellings. Masking the packed binary form
 * rather than the string means every spelling reduces to one value — otherwise
 * a compressed and an expanded address would produce different geo lookups.
 */
it('masks every spelling of the same address identically', function (): void {
    $compressed = anonymiser()->anonymise('2001:db8::1');
    $expanded = anonymiser()->anonymise('2001:0db8:0000:0000:0000:0000:0000:0001');

    expect($compressed)->toBe($expanded);
});

it('discards addresses within a subnet, so neighbours are indistinguishable', function (): void {
    $one = anonymiser()->anonymise('203.0.113.7');
    $two = anonymiser()->anonymise('203.0.113.200');

    expect($one)->toBe($two);
});

/**
 * A value that is not an address is more likely a spoofed header than a real
 * client. Passing it through unchanged would mean forwarding something
 * unexamined to a resolver.
 */
it('refuses anything that is not an address', function (string $input): void {
    expect(anonymiser()->anonymise($input))->toBeNull();
})->with([
    [''],
    ['not-an-ip'],
    ['203.0.113'],
    ['203.0.113.256'],
    ['999.999.999.999'],
    ['<script>alert(1)</script>'],
    ['203.0.113.1, 198.51.100.1'],
]);

it('tolerates surrounding whitespace', function (): void {
    expect(anonymiser()->anonymise('  203.0.113.42  '))->toBe('203.0.113.0');
});

/*
|--------------------------------------------------------------------------
| Routability
|--------------------------------------------------------------------------
|
| A lookup that could never succeed is skipped rather than performed and
| discarded, which matters when the resolver is paid for or rate-limited.
|
*/

/**
 * anonymise() only reaches maskIpv6() after filter_var() has already accepted
 * the address as valid IPv6, so this exercises the method's own fallback
 * directly rather than trying to find a string that fools one validator but
 * not the other.
 */
it('returns the address unchanged if it cannot be packed as IPv6', function (): void {
    $anonymiser = anonymiser();

    $method = new ReflectionMethod($anonymiser, 'maskIpv6');

    expect($method->invoke($anonymiser, 'not-an-ip'))->toBe('not-an-ip');
});

it('recognises addresses a geo lookup could answer', function (): void {
    expect(anonymiser()->isRoutable('8.8.8.8'))->toBeTrue()
        ->and(anonymiser()->isRoutable('203.0.113.7'))->toBeTrue();
});

it('recognises addresses no geo lookup could answer', function (string $ip): void {
    expect(anonymiser()->isRoutable($ip))->toBeFalse();
})->with([
    'loopback' => ['127.0.0.1'],
    'private class A' => ['10.0.0.1'],
    'private class B' => ['172.16.0.1'],
    'private class C' => ['192.168.1.1'],
    'link local' => ['169.254.1.1'],
    'ipv6 loopback' => ['::1'],
    'nonsense' => ['not-an-ip'],
]);
