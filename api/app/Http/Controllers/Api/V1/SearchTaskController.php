<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SearchTaskResource;
use App\Models\Search;
use App\Models\SearchTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchTaskController extends Controller
{
    public function index(Request $request, Search $search): AnonymousResourceCollection
    {
        $this->authorize('view', $search);

        $tasks = SearchTask::query()->where('search_id', $search->id)
            ->with(['searchSource', 'source'])
            ->when($request->query('stage'), fn ($q, $s) => $q->where('stage', $s))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')->limit(1000)->get();

        return SearchTaskResource::collection($tasks);
    }
}
