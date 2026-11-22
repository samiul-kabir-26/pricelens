<?php

namespace App\Scraping\Planning;

use App\Exceptions\ProblemException;
use App\Models\Search;
use App\Models\Tenant;
use App\Scraping\Fetching\FetchException;
use App\Scraping\Fetching\SafeHttpClient;
use App\Scraping\Matching\ImageSimilarity;
use Illuminate\Support\Facades\Storage;

/**
 * SSRF-guarded image download, MIME + magic-byte validation (≤10 MB), WebP re-encode, private storage, pHash.
 */
class ImageIngestor
{
    public function __construct(private SafeHttpClient $http, private ImageSimilarity $images) {}

    public function download(string $url): string
    {
        try {
            $result = $this->http->get($url, [
                'headers' => ['User-Agent' => config('pricelens.scraper.user_agent'), 'Accept' => 'image/webp,image/png,image/jpeg'],
                'max_bytes' => (int) config('pricelens.images.max_bytes'),
                'timeout' => 20,
            ]);
        } catch (FetchException $e) {
            throw new ProblemException('image_unavailable', 'Image could not be downloaded', 422, $e->getMessage());
        }

        if (! $result['response']->successful()) {
            throw new ProblemException('image_unavailable', 'Image could not be downloaded', 422, 'HTTP '.$result['response']->status());
        }

        return $result['response']->body();
    }

    /**
     * @return array{path: string, phash: ?string, mime: string}
     */
    public function store(Tenant $tenant, Search $search, string $bytes): array
    {
        $mime = self::sniffMime($bytes);
        if ($mime === null || ! in_array($mime, config('pricelens.images.allowed_mimes'), true)) {
            throw new ProblemException('invalid_image', 'Unsupported image type', 422, 'Only JPEG, PNG and WebP images are accepted.');
        }

        $encoded = $this->reencodeWebp($bytes);
        $ext = $encoded !== null ? 'webp' : match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $path = "{$tenant->storagePrefix()}/searches/{$search->ulid}/input.{$ext}";
        Storage::disk(config('filesystems.default'))->put($path, $encoded ?? $bytes, ['visibility' => 'private']);

        return ['path' => $path, 'phash' => $this->images->hash($bytes), 'mime' => $mime];
    }

    /** Magic-byte sniffing (independent of any client-provided Content-Type). */
    public static function sniffMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function reencodeWebp(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new ProblemException('invalid_image', 'Unreadable image', 422, 'The image could not be decoded.');
        }
        ob_start();
        imagewebp($img, null, 85);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out !== '' ? $out : null;
    }
}
