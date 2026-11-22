<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ApiKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->paginate(ApiKey::query()->when(! $request->boolean('include_revoked'), fn ($q) => $q->whereNull('revoked_at')), ApiKeyResource::class, $request);
    }

    public function store(ApiKeyRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$key, $plaintext] = ApiKey::generate($this->tenant(), $data['name'], $data['abilities'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null);
        AuditLog::record('api_key.created', $key, ['name' => $key->name, 'abilities' => $key->abilities]);

        $resource = new ApiKeyResource($key);
        $resource->plaintext = $plaintext;

        return $resource->response()->setStatusCode(201);
    }

    public function destroy(Request $request, ApiKey $apiKey): Response
    {
        $this->authorize('delete', $apiKey);
        $apiKey->update(['revoked_at' => now()]);
        AuditLog::record('api_key.revoked', $apiKey, ['name' => $apiKey->name]);

        return response()->noContent();
    }
}
