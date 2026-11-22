<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class OfferFound implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $offer OfferResource payload */
    public function __construct(public string $channel, public array $offer) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'OfferFound';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['offer' => $this->offer];
    }
}
