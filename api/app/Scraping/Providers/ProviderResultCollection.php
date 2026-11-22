<?php

namespace App\Scraping\Providers;

final class ProviderResultCollection
{
    /**
     * @param  array<int, ProviderResult>  $results
     * @param  array<int, string>  $labels  best-guess labels / entities (reverse image)
     */
    public function __construct(public array $results = [], public array $labels = []) {}

    /** @return array{results: array<int, array<string, mixed>>, labels: array<int, string>} */
    public function toArray(): array
    {
        return ['results' => array_map(fn (ProviderResult $r) => $r->toArray(), $this->results), 'labels' => $this->labels];
    }

    /** @param array{results?: array<int, array<string, mixed>>, labels?: array<int, string>} $a */
    public static function fromArray(array $a): self
    {
        return new self(array_map(fn ($r) => ProviderResult::fromArray($r), $a['results'] ?? []), $a['labels'] ?? []);
    }
}
