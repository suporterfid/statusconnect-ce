<?php

namespace Tests\Feature;

use App\Mail\AccountMail;
use App\Mail\IncidentNotificationMail;
use App\Mail\StatusPageSubscriberMail;
use Tests\TestCase;

/**
 * HTML emails share the public design system (#135): a station-sign band, the
 * tenant's accent on subscriber mail, status shown by roundel and label.
 */
final class MailDesignTest extends TestCase
{
    public function test_operator_alert_renders_the_opened_state_and_escapes_payload(): void
    {
        app()->setLocale('en');
        $html = (new IncidentNotificationMail([
            'event' => 'opened',
            'monitor_name' => 'Checkout <API>',
            'summary' => 'HTTP 503 <script>alert(1)</script>',
            'started_at' => '2026-11-09T21:00:00+00:00',
        ]))->render();

        $this->assertStringContainsString('Incident opened', $html);
        $this->assertStringContainsString('border:3px solid #B91C1C', $html);
        $this->assertStringContainsString('Checkout &lt;API&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('09/11/2026 21:00 UTC', $html);
        $this->assertStringContainsString('background:#252525', $html);
    }

    public function test_operator_alert_resolved_shows_duration(): void
    {
        app()->setLocale('pt_BR');
        $html = (new IncidentNotificationMail([
            'event' => 'resolved',
            'monitor_name' => 'Site',
            'summary' => 'ok',
            'started_at' => '2026-11-09T20:00:00+00:00',
            'resolved_at' => '2026-11-09T21:30:00+00:00',
            'duration_seconds' => 5400,
        ]))->render();

        $this->assertStringContainsString('Incidente resolvido', $html);
        $this->assertStringContainsString('background:#059669', $html);
        $this->assertStringContainsString('1 h 30 min', $html);
    }

    public function test_subscriber_mail_uses_the_tenant_accent_and_respects_powered_by(): void
    {
        app()->setLocale('en');
        $page = (object) ['accent_color' => '#0B3D91', 'show_powered_by' => false];
        $html = (new StatusPageSubscriberMail('incident', 'Subject', [
            'incidentKind' => 'incident_opened',
            'pageTitle' => 'Acme Status',
            'pageUrl' => 'https://status.acme.test',
            'components' => 'API',
            'startedAt' => '09/11/2026 18:00',
            'timezone' => 'America/Sao_Paulo',
        ] + StatusPageSubscriberMail::branding($page), 'https://app.test/unsubscribe'))->render();

        $this->assertStringContainsString('background:#0b3d91', $html);
        $this->assertStringContainsString('Acme Status', $html);
        $this->assertStringContainsString('https://app.test/unsubscribe', $html);
        $this->assertStringNotContainsString('Status page by', $html);
        $this->assertStringNotContainsString('github.com', $html);

        $html = (new StatusPageSubscriberMail('confirm', 'Subject', [
            'pageTitle' => 'Acme Status',
            'pageUrl' => 'https://status.acme.test',
            'url' => 'https://app.test/confirm',
            'hours' => 48,
        ] + StatusPageSubscriberMail::branding((object) [])))->render();

        $this->assertStringContainsString('background:#252525', $html);
        $this->assertStringContainsString('background:#1A6DC1', $html);
        $this->assertStringContainsString('Status page by', $html);
        $this->assertStringNotContainsString('github.com', $html);
    }

    public function test_account_mail_uses_the_shared_layout(): void
    {
        $html = (new AccountMail('verify', 'Subject', ['name' => 'Ana', 'url' => 'https://app.test/verify', 'hours' => 24]))->render();

        $this->assertStringContainsString('background:#252525', $html);
        $this->assertStringContainsString('https://app.test/verify', $html);
        $this->assertStringContainsString('/brand/icon-512.png', $html);
        $this->assertMatchesRegularExpression('#<title>[^<]+</title>#', $html);
    }

    public function test_account_mails_carry_state_details_and_the_legal_footer(): void
    {
        app()->setLocale('pt_BR');
        config(['legal.company_name' => 'Acme Monitoramento Ltda', 'legal.company_id' => '11.222.333/0001-81']);

        $approved = (new AccountMail('approved', 'Subject', ['name' => 'Ana', 'organization' => 'Acme', 'url' => 'https://app.test/login']))->render();
        $this->assertStringContainsString('Organização aprovada', $approved);
        $this->assertStringContainsString('background:#059669', $approved);
        $this->assertStringContainsString('Acme Monitoramento Ltda · CNPJ 11.222.333/0001-81', $approved);
        $this->assertStringContainsString('/termos', $approved);

        $admin = (new AccountMail('admin_new_signup', 'Subject', ['name' => 'Ana <x>', 'email' => 'ana@acme.test', 'organization' => 'Acme', 'url' => 'https://app.test/app']))->render();
        $this->assertStringContainsString('Aguardando aprovação', $admin);
        $this->assertStringContainsString('ana@acme.test', $admin);
        $this->assertStringContainsString('Ana &lt;x&gt;', $admin);

        $reset = (new AccountMail('reset', 'Subject', ['name' => 'Ana', 'url' => 'https://app.test/reset/abc', 'minutes' => 60]))->render();
        $this->assertStringContainsString('Redefina sua senha', $reset);
        $this->assertStringContainsString('copie este endereço', $reset);
    }
}
