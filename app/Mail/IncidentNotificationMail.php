<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IncidentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload)
    {
    }

    public function envelope(): Envelope
    {
        $event = strtoupper((string) ($this->payload['event'] ?? 'NOTIFICATION'));
        $monitorName = $this->payload['monitor_name'] ?? 'Monitor';

        return new Envelope(
            subject: "[StatusConnect] [{$event}] {$monitorName}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert', with: ['payload' => $this->payload]);
    }
}
