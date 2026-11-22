<?php

namespace App\Scraping\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth2 access token for a Google service account (JWT bearer grant, RS256), cached until shortly before
 * expiry. The token endpoint is pinned to Google's so tenant-supplied JSON can't redirect requests elsewhere.
 */
class GoogleServiceAccountToken
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-vision';

    /** @param array<string, mixed> $credentials parsed service-account JSON */
    public static function get(array $credentials): string
    {
        $email = $credentials['client_email'] ?? null;
        $key = $credentials['private_key'] ?? null;
        if (! is_string($email) || ! is_string($key)) {
            throw new ProviderException('provider_not_configured', 'Service-account JSON is missing client_email/private_key.');
        }

        return Cache::remember('google_sa_token:'.sha1($email.$key), 3300, function () use ($email, $key) {
            $now = time();
            $jwt = self::jwt(['alg' => 'RS256', 'typ' => 'JWT'], [
                'iss' => $email, 'scope' => self::SCOPE, 'aud' => self::TOKEN_URI, 'iat' => $now, 'exp' => $now + 3600,
            ], $key);

            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! is_string($token = $response->json('access_token'))) {
                throw new ProviderException('provider_auth_failed', 'Google token exchange failed: HTTP '.$response->status());
            }

            return $token;
        });
    }

    /** @return array<string, mixed>|null */
    public static function load(?string $path): ?array
    {
        if (! $path || ! is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function jwt(array $header, array $claims, string $privateKeyPem): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64((string) json_encode($header)).'.'.$b64((string) json_encode($claims));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ProviderException('provider_not_configured', 'Invalid service-account private key.');
        }

        return $input.'.'.$b64($signature);
    }
}
