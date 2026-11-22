<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class Problem
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(string $code, string $title, int $status, ?string $detail = null, array $extra = [], array $headers = []): JsonResponse
    {
        $body = array_filter([
            'type' => config('pricelens.errors_base_url').$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.ltrim(request()->path(), '/'),
        ], fn ($v) => $v !== null) + $extra;

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }
}
