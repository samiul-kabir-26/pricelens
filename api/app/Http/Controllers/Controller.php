<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class Controller
{
    use AuthorizesRequests;

    protected function tenant(): Tenant
    {
        return CurrentTenant::instance()->get();
    }

    protected function limit(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('limit', (string) $default)));
    }

    /**
     * Cursor pagination → { data: [...], meta: { next_cursor } }.
     *
     * @param  Builder<covariant Model>  $query
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginate(Builder $query, string $resource, Request $request): AnonymousResourceCollection
    {
        $page = $query->orderByDesc($query->getModel()->qualifyColumn('id'))->cursorPaginate($this->limit($request));

        return $resource::collection($page->items())->additional(['meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }
}
