<?php

use App\Scraping\Providers\GoogleServiceAccountToken;
use App\Scraping\Providers\GoogleVisionProvider;
use App\Scraping\Providers\ProviderException;
use App\Scraping\Providers\ProviderQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('signs a JWT, exchanges it, and calls Vision with a bearer token', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $public = openssl_pkey_get_details($key)['key'];

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'vision.googleapis.com/*' => Http::response(['responses' => [['webDetection' => [
            'bestGuessLabels' => [['label' => 'sony wh-1000xm5']],
            'pagesWithMatchingImages' => [['url' => 'https://shop.example.com/p/xm5', 'pageTitle' => '<b>Sony</b> XM5']],
        ]]]]),
    ]);

    $provider = new GoogleVisionProvider(null, 'https://vision.googleapis.com/v1/images:annotate', [
        'client_email' => 'sa@proj.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://evil.example.com/token',
    ]);
    $result = $provider->search(new ProviderQuery(mode: 'reverse_image', imageUrl: 'https://cdn.example.com/x.jpg'));

    expect($result->labels)->toBe(['sony wh-1000xm5'])->and($result->results[0]->title)->toBe('Sony XM5');

    Http::assertSent(function (Request $r) use ($public) {
        if ($r->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$h, $c, $sig] = explode('.', $r['assertion']);
        $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'));

        return json_decode($decode($c), true)['scope'] === 'https://www.googleapis.com/auth/cloud-vision'
            && openssl_verify("{$h}.{$c}", $decode($sig), $public, OPENSSL_ALGO_SHA256) === 1;
    });
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://vision.googleapis.com/') && $r->header('Authorization')[0] === 'Bearer ya29.test'
        && ! str_contains($r->url(), 'key='));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com'));
});

it('rejects incomplete service-account JSON', function () {
    expect(fn () => GoogleServiceAccountToken::get(['client_email' => 'x']))->toThrow(ProviderException::class);
});
