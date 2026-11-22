<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SearchCompleted implements ShouldBroadcastNow
{
    /** @param array<string, mixed>|null $stats */
    public function __construct(public string $channel, public string $searchId, public string $status, public ?array $stats) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'SearchCompleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['search_id' => $this->searchId, 'status' => $this->status, 'stats' => $this->stats];
    }
}
