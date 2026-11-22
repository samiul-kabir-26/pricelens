<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SearchProgressUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array{total: int, done: int}  $progress
     * @param  array<int, array<string, mixed>>  $sources
     */
    public function __construct(
        public string $channel,
        public string $searchId,
        public string $status,
        public array $progress,
        public array $sources,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'SearchProgressUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['search_id' => $this->searchId, 'status' => $this->status, 'progress' => $this->progress, 'sources' => $this->sources];
    }
}
