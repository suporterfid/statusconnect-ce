<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Account emails (#120): verification, approval, password reset, the invitation
 * to set a password for a user a platform admin created (#248), and the
 * platform admin notice of a new sign-up. One mailable, one view per kind.
 */
final class AccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KINDS = ['verify', 'approved', 'reset', 'admin_new_signup', 'admin_checker_gap', 'admin_nfse_error', 'trial_ending', 'trial_ended', 'admin_signup_digest', 'invite'];

    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $kind, public readonly string $subjectLine, public readonly array $data)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account.'.$this->kind, with: $this->data + ['appName' => config('app.name')]);
    }
}
