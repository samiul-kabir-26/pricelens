<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\OpenApiContract;

abstract class TestCase extends BaseTestCase
{
    /** Set false in a test to skip contract validation (e.g. deliberately malformed routes). */
    protected bool $validateContract = true;

    /**
     * Every /api/v1 (and /metrics) response produced in a test is checked against openapi.yaml, so the
     * spec can't drift from the code.
     */
    protected function createTestResponse($response, $request)
    {
        $testResponse = parent::createTestResponse($response, $request);

        $path = '/'.ltrim($request->path(), '/');
        if ($this->validateContract && OpenApiContract::available() && (str_starts_with($path, '/api/v1/') || $path === '/metrics')
            && ! $testResponse->baseResponse instanceof StreamedResponse) {
            $violations = OpenApiContract::check($request->method(), $path, $testResponse->getStatusCode(),
                $testResponse->headers->get('Content-Type'), (string) $testResponse->getContent());
            if ($violations !== []) {
                $this->fail("OpenAPI contract violation:\n- ".implode("\n- ", array_slice($violations, 0, 10)));
            }
        }

        return $testResponse;
    }
}
