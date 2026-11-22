<?php

use App\Scraping\Fetching\FetchException;
use App\Scraping\Fetching\SafeHttpClient;
use App\Scraping\Fetching\SafeUrlGuard;
use App\Scraping\Fetching\UnsafeUrlException;
use App\Webhooks\Signer;
use Illuminate\Support\Facades\Http;

it('blocks private, loopback, link-local, metadata and mapped addresses', function (string $ip) {
    expect(SafeUrlGuard::isBlockedIp($ip))->toBeTrue();
})->with(['10.1.2.3', '172.16.5.4', '172.31.255.255', '192.168.1.1', '127.0.0.1', '169.254.169.254', '100.64.0.1',
    '0.0.0.0', '::1', 'fc00::1', 'fd00:ec2::254', 'fe80::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '224.0.0.1']);

it('allows public addresses', function (string $ip) {
    expect(SafeUrlGuard::isBlockedIp($ip))->toBeFalse();
})->with(['93.184.216.34', '8.8.8.8', '172.32.0.1', '2606:4700:4700::1111']);

it('rejects unsafe URLs', function (string $url, string $reason) {
    $guard = new SafeUrlGuard(fn (string $host) => $host === 'rebind.example.com' ? ['93.184.216.34', '10.0.0.5'] : ['93.184.216.34']);
    try {
        $guard->check($url);
        $this->fail('expected exception');
    } catch (UnsafeUrlException $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    ['file:///etc/passwd', 'scheme_not_allowed'],
    ['gopher://example.com/', 'scheme_not_allowed'],
    ['http://user:pass@example.com/', 'credentials_in_url'],
    ['http://127.0.0.1/admin', 'ssrf_blocked'],
    ['http://[::1]/', 'ssrf_blocked'],
    ['http://169.254.169.254/latest/meta-data/', 'ssrf_blocked'],
    ['http://localhost:8080/', 'ssrf_blocked'],
    ['http://metadata.google.internal/', 'ssrf_blocked'],
    ['http://rebind.example.com/', 'ssrf_blocked'],
    ['not a url', 'invalid_url'],
]);

it('re-checks every redirect hop', function () {
    $guard = new SafeUrlGuard(fn (string $host) => $host === 'internal.example.com' ? ['10.0.0.7'] : ['93.184.216.34']);
    Http::fake([
        'public.example.com/*' => Http::response('', 302, ['Location' => 'http://internal.example.com/secret']),
        '*' => Http::response('secret'),
    ]);

    expect(fn () => (new SafeHttpClient($guard))->get('https://public.example.com/start'))
        ->toThrow(FetchException::class);
    Http::assertSentCount(1);
});

it('caps response body size', function () {
    Http::fake(['*' => Http::response(str_repeat('a', 2048))]);
    $client = new SafeHttpClient(new SafeUrlGuard(fn () => ['93.184.216.34']));

    expect(fn () => $client->get('https://big.example.com/', ['max_bytes' => 1024]))
        ->toThrow(fn (FetchException $e) => expect($e->errorCode)->toBe('too_large'));
});

it('signs and verifies webhook payloads', function () {
    $sig = Signer::sign('whsec_test', 1700000000, '{"a":1}');

    expect($sig)->toBe('v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_test'))
        ->and(Signer::verify('whsec_test', $sig, 1700000000, '{"a":1}', now: 1700000100))->toBeTrue()
        ->and(Signer::verify('whsec_test', $sig, 1700000000, '{"a":2}', now: 1700000100))->toBeFalse()
        ->and(Signer::verify('whsec_test', $sig, 1700000000, '{"a":1}', now: 1700000000 + 301))->toBeFalse();
});
