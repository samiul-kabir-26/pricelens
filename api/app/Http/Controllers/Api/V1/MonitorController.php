<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\QuotaGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\MonitorRequest;
use App\Http\Resources\MonitorResource;
use App\Http\Resources\SearchResource;
use App\Models\Monitor;
use App\Models\Source;
use App\Models\TrackedProduct;
use App\Services\MonitorRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class MonitorController extends Controller
{
    public function __construct(private QuotaGuard $quota) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Monitor::query()->with('trackedProduct')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('tracked_product_id'), fn ($q, $id) => $q->whereHas('trackedProduct', fn ($t) => $t->where('ulid', $id)));

        return $this->paginate($query, MonitorResource::class, $request);
    }

    public function store(MonitorRequest $request): JsonResponse
    {
        $data = $this->data($request);
        $product = TrackedProduct::query()->where('ulid', $data['tracked_product_id'])->first()
            ?? throw ValidationException::withMessages(['tracked_product_id' => 'Unknown tracked product.']);
        $this->assertSources($data['source_ids'] ?? []);

        $status = $data['status'] ?? 'active';
        if ($status === 'active') {
            $this->quota->assertCanActivateMonitor($this->tenant());
        }

        $monitor = new Monitor([
            'tracked_product_id' => $product->id,
            'schedule' => $data['schedule'],
            'timezone' => $data['timezone'] ?? 'UTC',
            'source_ids' => $data['source_ids'] ?? null,
            'options' => $data['options'] ?? null,
            'alert_rules' => $data['alert_rules'] ?? null,
            'status' => $status,
        ]);
        $monitor->next_run_at = $monitor->computeNextRun();
        $monitor->save();

        return (new MonitorResource($monitor->load('trackedProduct')))->response()->setStatusCode(201);
    }

    public function show(Monitor $monitor): MonitorResource
    {
        $this->authorize('view', $monitor);

        return new MonitorResource($monitor->load('trackedProduct'));
    }

    public function update(MonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $this->authorize('update', $monitor);
        $data = $this->data($request);
        $this->assertSources($data['source_ids'] ?? []);

        if (($data['status'] ?? null) === 'active' && $monitor->status !== 'active') {
            $this->quota->assertCanActivateMonitor($this->tenant(), $monitor);
        }

        $monitor->fill(collect($data)->only(['schedule', 'timezone', 'source_ids', 'options', 'alert_rules', 'status'])->all());
        if ($monitor->isDirty(['schedule', 'timezone', 'status'])) {
            $monitor->next_run_at = $monitor->computeNextRun();
        }
        $monitor->save();

        return new MonitorResource($monitor->load('trackedProduct'));
    }

    public function destroy(Monitor $monitor): Response
    {
        $this->authorize('delete', $monitor);
        $monitor->delete();

        return response()->noContent();
    }

    public function run(Monitor $monitor, MonitorRunner $runner): JsonResponse
    {
        $this->authorize('update', $monitor);
        $search = $runner->run($this->tenant(), $monitor);

        return (new SearchResource($search->refresh()))->response()->setStatusCode(202);
    }

    /**
     * options / alert_rules are free-form objects whose known keys are validated; keep the unknown ones.
     *
     * @return array<string, mixed>
     */
    private function data(MonitorRequest $request): array
    {
        $data = $request->validated();
        foreach (['options', 'alert_rules'] as $key) {
            if ($request->has($key)) {
                $data[$key] = $request->input($key);
            }
        }

        return $data;
    }

    /** @param array<int, string> $ids */
    private function assertSources(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $found = Source::query()->whereIn('ulid', $ids)->count();
        if ($found !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['source_ids' => 'One or more sources are unknown.']);
        }
    }
}
