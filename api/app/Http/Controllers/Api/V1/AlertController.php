<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['type' => ['nullable', Rule::in(Alert::TYPES)], 'acknowledged' => ['nullable', 'boolean']]);

        $query = Alert::query()->with(['monitor', 'trackedProduct'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('tracked_product_id'), fn ($q, $id) => $q->whereHas('trackedProduct', fn ($t) => $t->where('ulid', $id)))
            ->when($request->query('monitor_id'), fn ($q, $id) => $q->whereHas('monitor', fn ($t) => $t->where('ulid', $id)))
            ->when($request->has('acknowledged'), fn ($q) => $request->boolean('acknowledged') ? $q->whereNotNull('acknowledged_at') : $q->whereNull('acknowledged_at'));

        return $this->paginate($query, AlertResource::class, $request);
    }

    public function acknowledge(Alert $alert): AlertResource
    {
        $this->authorize('update', $alert);
        $alert->acknowledged_at ??= now();
        $alert->save();

        return new AlertResource($alert->load(['monitor', 'trackedProduct']));
    }
}
