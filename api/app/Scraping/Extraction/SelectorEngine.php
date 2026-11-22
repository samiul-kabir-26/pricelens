<?php

namespace App\Scraping\Extraction;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Adapter selector syntax: CSS by default, `xpath:` prefix for XPath, `@attr` suffix to read an attribute.
 * Object form: { "selector": "...", "json_path": "$.x", "regex": "...", "static": "USD" }.
 */
class SelectorEngine
{
    private const MAX_SELECTOR_LENGTH = 500;

    /**
     * @param  string|array<string, mixed>  $spec
     * @return array<int, string>
     */
    public function query(Crawler $root, string|array $spec, int $limit = 50): array
    {
        if (is_array($spec)) {
            if (array_key_exists('static', $spec)) {
                return [(string) $spec['static']];
            }
            $values = isset($spec['selector']) ? $this->query($root, (string) $spec['selector'], $limit) : [];
            if (! empty($spec['json_path'])) {
                $values = array_values(array_filter(array_map(function ($v) use ($spec) {
                    $decoded = JsonPath::decodeLenient($v);
                    $found = $decoded === null ? null : $this->firstFromJsonLd($decoded, (string) $spec['json_path']);

                    return is_scalar($found) ? (string) $found : null;
                }, $values)));
            }
            if (! empty($spec['regex'])) {
                $values = $this->applyRegex($values, (string) $spec['regex']);
            }

            return $values;
        }

        $spec = trim($spec);
        if ($spec === '' || strlen($spec) > self::MAX_SELECTOR_LENGTH) {
            return [];
        }

        [$selector, $attr] = $this->splitAttr($spec);

        try {
            $nodes = str_starts_with($selector, 'xpath:')
                ? $root->filterXPath(substr($selector, 6))
                : $root->filter($selector);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($nodes as $i => $node) {
            if ($i >= $limit) {
                break;
            }
            $value = $attr !== null
                ? ($node instanceof \DOMElement ? $node->getAttribute($attr) : null)
                : $node->textContent;
            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** @param string|array<string, mixed> $spec */
    public function first(Crawler $root, string|array $spec): ?string
    {
        return $this->query($root, $spec, 1)[0] ?? null;
    }

    /**
     * Regex post-processing with a backtrack limit as a guard against catastrophic patterns.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public function applyRegex(array $values, string $pattern): array
    {
        $delimited = '~'.str_replace('~', '\~', $pattern).'~u';
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10000');

        try {
            $out = [];
            foreach ($values as $v) {
                $ok = @preg_match($delimited, $v, $m);
                if ($ok === 1) {
                    $out[] = $m[1] ?? $m[0];
                }
            }

            return $out;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    public static function isValidRegex(string $pattern): bool
    {
        return strlen($pattern) <= 300 && @preg_match('~'.str_replace('~', '\~', $pattern).'~u', '') !== false;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitAttr(string $spec): array
    {
        if (preg_match('/^(.*[^\s@])@([A-Za-z_:][\w:.-]*)$/', $spec, $m) && substr_count($m[1], '[') === substr_count($m[1], ']')) {
            return [$m[1], $m[2]];
        }

        return [$spec, null];
    }

    private function firstFromJsonLd(mixed $decoded, string $path): mixed
    {
        $found = JsonPath::get($decoded, $path);
        if ($found === null && is_array($decoded) && array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (($found = JsonPath::get($item, $path)) !== null) {
                    break;
                }
            }
        }

        return $found;
    }
}
