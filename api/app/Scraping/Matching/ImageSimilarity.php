<?php

namespace App\Scraping\Matching;

use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Throwable;

/**
 * pHash (64-bit perceptual hash); similarity = 1 - hamming/64.
 */
class ImageSimilarity
{
    public static function available(): bool
    {
        return extension_loaded('gd') || extension_loaded('imagick');
    }

    public function hash(string $imageBytes): ?string
    {
        if (! self::available()) {
            return null;
        }

        try {
            return (new ImageHash(new PerceptualHash))->hash($imageBytes)->toHex();
        } catch (Throwable) {
            return null;
        }
    }

    public static function similarity(?string $hexA, ?string $hexB): ?float
    {
        if (! $hexA || ! $hexB || ! ctype_xdigit($hexA) || ! ctype_xdigit($hexB)) {
            return null;
        }

        $distance = self::hamming($hexA, $hexB);

        return max(0.0, 1 - $distance / 64);
    }

    /** Bitwise Hamming distance between two hex strings (nibble-wise; no float precision loss). */
    public static function hamming(string $hexA, string $hexB): int
    {
        $len = max(strlen($hexA), strlen($hexB));
        $a = str_pad(strtolower($hexA), $len, '0', STR_PAD_LEFT);
        $b = str_pad(strtolower($hexB), $len, '0', STR_PAD_LEFT);
        $distance = 0;
        for ($i = 0; $i < $len; $i++) {
            $x = hexdec($a[$i]) ^ hexdec($b[$i]);
            $distance += substr_count(decbin($x), '1');
        }

        return $distance;
    }
}
