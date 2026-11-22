<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebhookEndpointDisabled extends Notification
{
    use Queueable;

    public function __construct(public string $endpointId, public string $url, public string $reason) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PriceLens webhook endpoint disabled')
            ->line("Your webhook endpoint {$this->url} ({$this->endpointId}) was disabled: {$this->reason}.")
            ->line('Fix the receiver, then re-enable it with PATCH /api/v1/webhooks/{id} {"enabled": true}.');
    }
}
