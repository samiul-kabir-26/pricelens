<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates API responses against ../openapi.yaml (OpenAPI 3.1 = JSON Schema 2020-12 for schemas).
 * Used automatically for every /api/v1 JSON response in feature tests (see Tests\TestCase).
 */
final class OpenApiContract
{
    private const DOC_ID = 'https://pricelens.test/openapi.json';

    private static ?array $doc = null;

    private static ?Validator $validator = null;

    /** @var array<int, array{regex: string, template: string}> */
    private static array $templates = [];

    public static function available(): bool
    {
        return is_file(self::path());
    }

    /**
     * @return array<int, string> violations (empty = conforms)
     */
    public static function check(string $method, string $path, int $status, ?string $contentType, string $body): array
    {
        self::boot();
        $method = strtolower($method);
        $template = self::template($path);
        if ($template === null) {
            return ["{$method} {$path}: path not documented in openapi.yaml"];
        }
        $operation = self::$doc['paths'][$template][$method] ?? null;
        if ($operation === null) {
            return ["{$method} {$template}: operation not documented"];
        }
        $responses = $operation['responses'] ?? [];
        $statusKey = isset($responses[(string) $status]) ? (string) $status : (isset($responses['default']) ? 'default' : null);
        if ($statusKey === null) {
            return ["{$method} {$template}: status {$status} not documented (documented: ".implode(', ', array_keys($responses)).')'];
        }

        $response = self::deref($responses[$statusKey]);
        $media = strtolower(trim(explode(';', (string) $contentType)[0]));
        if (! isset($response['content']) || $media === '' || $body === '') {
            return [];
        }
        if (! isset($response['content'][$media])) {
            return ["{$method} {$template} {$status}: content type '{$media}' not documented"];
        }
        if (! str_contains($media, 'json') || ! isset($response['content'][$media]['schema'])) {
            return [];
        }

        $pointer = self::pointer($responses[$statusKey], $template, $method, $statusKey, $media);
        $result = self::$validator->validate(json_decode($body), (object) ['$ref' => self::DOC_ID.$pointer]);
        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), true);
        $flat = [];
        foreach ($errors as $where => $messages) {
            foreach ((array) $messages as $m) {
                $flat[] = "{$method} {$template} {$status} at '{$where}': {$m}";
            }
        }

        return $flat;
    }

    private static function boot(): void
    {
        if (self::$doc !== null) {
            return;
        }
        self::$doc = Yaml::parseFile(self::path());
        self::$validator = new Validator;
        self::$validator->setMaxErrors(5);
        self::$validator->resolver()->registerRaw(json_decode((string) json_encode(self::$doc)), self::DOC_ID);

        foreach (array_keys(self::$doc['paths']) as $template) {
            $regex = '#^/api/v1'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($template, '#')).'$#';
            if ($template === '/metrics') {
                $regex = '#^/metrics$#';
            }
            self::$templates[] = ['regex' => $regex, 'template' => $template];
        }
        // Literal segments win over parameters (e.g. /sources/global vs /sources/{source}).
        usort(self::$templates, fn ($a, $b) => substr_count($a['template'], '{') <=> substr_count($b['template'], '{'));
    }

    private static function template(string $path): ?string
    {
        $path = '/'.ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
        foreach (self::$templates as $t) {
            if (preg_match($t['regex'], $path)) {
                return $t['template'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private static function deref(array $node): array
    {
        while (isset($node['$ref'])) {
            $parts = array_slice(explode('/', $node['$ref']), 1);
            $node = self::$doc;
            foreach ($parts as $p) {
                $node = $node[str_replace(['~1', '~0'], ['/', '~'], $p)];
            }
        }

        return $node;
    }

    /** JSON pointer to the response schema, following a `$ref`'d shared response if present. */
    private static function pointer(array $response, string $template, string $method, string $status, string $media): string
    {
        // RFC 6901 escaping, then URI-fragment encoding ({param} braces are not valid in a URI fragment).
        $esc = fn (string $s) => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $s));
        if (isset($response['$ref'])) {
            return $response['$ref'].'/content/'.$esc($media).'/schema';
        }

        return '#/paths/'.$esc($template).'/'.$method.'/responses/'.$status.'/content/'.$esc($media).'/schema';
    }

    private static function path(): string
    {
        return dirname(__DIR__, 3).'/openapi.yaml';
    }
}
