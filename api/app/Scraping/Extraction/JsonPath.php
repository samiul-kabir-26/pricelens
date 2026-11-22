<?php

namespace App\Scraping\Extraction;

/**
 * Tiny JSONPath subset: `$.a.b[0].c`, `$.a[*].b` (first match), `$..key` (recursive descent, first match),
 * `$['key with space']`.
 */
final class JsonPath
{
    public static function get(mixed $data, string $path): mixed
    {
        $path = trim($path);
        if ($path === '' || $path === '$') {
            return $data;
        }

        if (str_starts_with($path, '$..')) {
            [$key, $rest] = array_pad(preg_split('/(?=[.\[])/', substr($path, 3), 2) ?: [], 2, '');

            $found = self::findRecursive($data, $key);

            return $found === null || $rest === '' ? $found : self::get($found, '$'.$rest);
        }

        $tokens = self::tokenize($path);

        return self::walk($data, $tokens);
    }

    /** @return array<int, string> */
    private static function tokenize(string $path): array
    {
        $path = preg_replace('/^\$\.?/', '', $path) ?? $path;
        preg_match_all('/\[\'([^\']+)\'\]|\["([^"]+)"\]|\[(\d+|\*)\]|([^.\[\]]+)/', $path, $m, PREG_SET_ORDER);

        // Exactly one capture group matches per token; PCRE omits trailing unmatched groups.
        return array_map(function (array $x): string {
            foreach ([1, 2, 3, 4] as $group) {
                $value = $x[$group] ?? '';
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        }, $m);
    }

    /** @param array<int, string> $tokens */
    private static function walk(mixed $data, array $tokens): mixed
    {
        foreach ($tokens as $i => $token) {
            if ($token === '*') {
                if (! is_array($data)) {
                    return null;
                }
                foreach ($data as $item) {
                    $value = self::walk($item, array_slice($tokens, $i + 1));
                    if ($value !== null) {
                        return $value;
                    }
                }

                return null;
            }
            if (! is_array($data) || ! array_key_exists($token, $data)) {
                return null;
            }
            $data = $data[$token];
        }

        return $data;
    }

    private static function findRecursive(mixed $data, string $key): mixed
    {
        if (! is_array($data)) {
            return null;
        }
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach ($data as $value) {
            $found = self::findRecursive($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Lenient JSON decode for page-embedded payloads (CDATA, HTML comments, trailing commas, JS assignment). */
    public static function decodeLenient(string $raw): mixed
    {
        $s = trim($raw);
        $s = preg_replace(['/^\s*<!\[CDATA\[/', '/\]\]>\s*$/', '/^\s*<!--/', '/-->\s*$/'], '', $s) ?? $s;

        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // `window.__STATE__ = {...};` → the outermost object/array.
        $start = strcspn($s, '{[');
        $endObj = strrpos($s, '}');
        $endArr = strrpos($s, ']');
        $end = max($endObj === false ? -1 : $endObj, $endArr === false ? -1 : $endArr);
        if ($start < strlen($s) && $end > $start) {
            $s = substr($s, $start, $end - $start + 1);
        }

        $s = preg_replace('/,\s*([}\]])/', '$1', $s) ?? $s;            // trailing commas
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s; // control chars
        $decoded = json_decode($s, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
