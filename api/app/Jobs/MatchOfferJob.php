<?php

namespace App\Jobs;

use App\Scraping\Data\ExtractedProduct;
use App\Services\OfferRecorder;

/**
 * Normalizes + scores + persists an offer that came without a page fetch (shopping engine, feed, listing mode).
 */
class MatchOfferJob extends PipelineJob
{
    /** @param array{url: string, product: array<string, mixed>, site_name: ?string, verify: bool} $directOffer */
    public function __construct(int $tenantId, int $searchId, ?int $searchSourceId, int $taskId, public array $directOffer)
    {
        parent::__construct($tenantId, $searchId, $searchSourceId, $taskId);
    }

    public function handle(OfferRecorder $recorder): void
    {
        $this->startTask();
        $product = ExtractedProduct::fromArray($this->directOffer['product']);

        $result = $recorder->record($this->tenant(), $this->search(), $this->searchSource(), $this->directOffer['url'], $product, null, $this->directOffer['site_name'] ?? null);

        $result['offer']
            ? $this->completeTask('succeeded')
            : $this->failTask('failed', (string) $result['error']);
    }
}
