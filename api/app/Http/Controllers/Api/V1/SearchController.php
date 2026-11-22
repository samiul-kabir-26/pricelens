<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSearchRequest;
use App\Http\Resources\SearchResource;
use App\Models\Search;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    public function __construct(private SearchService $searches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'running', 'completed', 'partially_completed', 'failed', 'cancelled'])],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'monitor_id' => ['nullable', 'ulid'],
        ]);

        $query = Search::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('created_from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('created_to'), fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->when($request->query('monitor_id'), fn ($q, $m) => $q->whereHas('monitor', fn ($mq) => $mq->where('ulid', $m)));

        return $this->paginate($query, SearchResource::class, $request);
    }

    public function store(StoreSearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $input = collect($data)->only(['title', 'gtin', 'mpn', 'brand', 'image_url', 'reference_url', 'sources'])->all();

        $search = $this->searches->create(
            $this->tenant(), $input, (array) ($data['options'] ?? []), $request->file('image'),
            $request->attributes->get('idempotency_key') ?? $request->header('Idempotency-Key'),
        );

        return (new SearchResource($search->refresh()))->response()->setStatusCode(202)
            ->header('Location', "/api/v1/searches/{$search->ulid}");
    }

    public function show(Search $search): SearchResource
    {
        $this->authorize('view', $search);

        return new SearchResource($search);
    }

    /** The normalized input image, via a short-lived signed URL (S3 pre-signed or Laravel's signed local route). */
    public function image(Search $search): RedirectResponse
    {
        $this->authorize('view', $search);
        $path = $search->product_query['image_path'] ?? null;
        abort_if(! $path, 404);

        return redirect()->away(Storage::disk(config('filesystems.default'))->temporaryUrl($path, now()->addMinutes(5)));
    }

    public function cancel(Search $search): SearchResource
    {
        $this->authorize('update', $search);

        return new SearchResource($this->searches->cancel($this->tenant(), $search));
    }

    public function rerun(Search $search): JsonResponse
    {
        $this->authorize('view', $search);
        $new = $this->searches->rerun($this->tenant(), $search);

        return (new SearchResource($new->refresh()))->response()->setStatusCode(202);
    }
}
