<?php

namespace App\Mail;

use App\Domain\StatusPage\AccentColor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Emails to status page subscribers (#92): confirmation and notifications.
 * Notifications carry List-Unsubscribe with one-click unsubscribe (RFC 8058).
 */
final class StatusPageSubscriberMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectLine,
        public readonly array $data,
        public readonly ?string $unsubscribeUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * The page's email branding: its accent colour (with readable text) for the
     * header band, and whether StatusConnect is credited.
     *
     * @return array{accent: ?string, accentText: ?string, poweredBy: bool}
     */
    public static function branding(object $page): array
    {
        $accent = AccentColor::normalize($page->accent_color ?? null);

        return [
            'accent' => $accent,
            'accentText' => $accent === null ? null : AccentColor::textColorFor($accent),
            'poweredBy' => (bool) ($page->show_powered_by ?? true),
        ];
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscriber.'.$this->kind, with: $this->data + ['unsubscribeUrl' => $this->unsubscribeUrl]);
    }
}
