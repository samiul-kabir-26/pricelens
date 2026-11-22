<?php

namespace App\Jobs;

use App\Billing\QuotaGuard;
use App\Scraping\Extraction\ExtractionPipeline;
use App\Scraping\Fetching\RenderHeuristics;
use App\Scraping\Fetching\SnapshotStore;
use App\Services\OfferRecorder;
use App\Services\SourceHealthRecorder;

/**
 * Stage 4: run the extractor chain on the stored snapshot. With render=auto and no price on a page that
 * looks client-rendered, hand off to RenderCandidateJob; otherwise normalize + match + persist the offer.
 */
class ExtractOfferJob extends PipelineJob
{
    public function __construct(
        int $tenantId, int $searchId, ?int $searchSourceId, int $taskId,
        public string $url,
        public string $finalUrl,
        public string $snapshotPath,
        public bool $rendered = false,
    ) {
        parent::__construct($tenantId, $searchId, $searchSourceId, $taskId);
    }

    public function handle(SnapshotStore $snapshots, ExtractionPipeline $pipeline, OfferRecorder $recorder, SourceHealthRecorder $health, QuotaGuard $quota): void
    {
        $this->startTask();
        $started = hrtime(true);
        $source = $this->source();

        $html = $snapshots->load($this->snapshotPath);
        if ($html === null) {
            $this->failTask('failed', 'parse_error', 'Snapshot missing.');

            return;
        }

        $renderMode = $source->render ?? 'auto';
        $mayRender = ! $this->rendered && $renderMode === 'auto' && $quota->canRender($this->tenant());

        $product = $pipeline->run($html, $this->finalUrl, $source, $this->tenant(), allowLlm: ! $mayRender);

        if (! $product->hasPrice() && $mayRender && RenderHeuristics::looksClientRendered($html)) {
            $this->completeTask('succeeded', ['error_code' => 'needs_render', 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)]);
            $task = $this->newTask('render', $this->url);
            $job = (new RenderCandidateJob($this->tenantId, $this->searchId, $this->searchSourceId, $task->id, $this->url))->onQueue('render');
            if ($this->batch() && ! $this->batch()->cancelled()) {
                $this->batch()->add([$job]);
            }

            return;
        }

        if ($reason = ExtractionPipeline::failureReason($product)) {
            if ($mayRender) {
                // Static page without price that doesn't look client-rendered: give the LLM fallback a chance.
                $product = $pipeline->run($html, $this->finalUrl, $source, $this->tenant(), allowLlm: true);
                $reason = ExtractionPipeline::failureReason($product);
            }
        }
        if ($reason) {
            $this->failTask('failed', $reason);

            return;
        }

        $result = $recorder->record($this->tenant(), $this->search(), $this->searchSource(), $this->finalUrl, $product, $this->snapshotPath, $source?->name);
        $duration = (int) ((hrtime(true) - $started) / 1e6);

        if ($result['offer'] === null) {
            $this->failTask('failed', (string) $result['error']);

            return;
        }

        $this->completeTask('succeeded', ['duration_ms' => $duration]);
        if ($source) {
            $health->record($source, 'extract', 'ok', $duration);
        }
    }
}
