<?php

namespace App\Services;

use App\Events\SearchProgressUpdated;
use App\Models\Search;
use App\Models\SearchSource;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Progress counters + throttled (≤ 2/s per search) SearchProgressUpdated broadcasts.
 */
class SearchProgress
{
    public function tasksAdded(Search $search, int $count): void
    {
        if ($count > 0) {
            Search::query()->whereKey($search->id)->increment('progress_total', $count);
        }
    }

    public function taskFinished(Search $search): void
    {
        Search::query()->whereKey($search->id)->increment('progress_done');
        $this->broadcast($search);
    }

    public function broadcast(Search $search, bool $force = false): void
    {
        $perSecond = max(1, (int) config('pricelens.realtime.progress_broadcasts_per_second', 2));
        $slot = (int) floor(microtime(true) * $perSecond);
        if (! $force && ! Cache::add("search:{$search->id}:progress:{$slot}", 1, 2)) {
            return;
        }

        $fresh = Search::query()->find($search->id);
        if (! $fresh) {
            return;
        }

        try {
            event(new SearchProgressUpdated(
                $fresh->channelName(),
                $fresh->ulid,
                $fresh->status,
                ['total' => $fresh->progress_total, 'done' => min($fresh->progress_done, $fresh->progress_total)],
                $this->sources($fresh),
            ));
        } catch (Throwable $e) {
            report($e); // never let the realtime layer break the pipeline
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function sources(Search $search): array
    {
        return SearchSource::query()->where('search_id', $search->id)->with('source')->get()
            ->map(fn (SearchSource $s) => [
                'source_id' => $s->source?->ulid,
                'name' => $s->label,
                'domain' => $s->domain,
                'type' => $s->type,
                'status' => $s->status,
                'candidates_found' => $s->candidates_found,
                'offers_found' => $s->offers_found,
                'error' => $s->error,
            ])->all();
    }
}
