<?php

namespace App\Application\Legal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Terms of Use and Privacy Policy (#122) and their versions (terms acceptance).
 *
 * The Markdown templates (resources/legal, or the edition's own) are filled in from the LEGAL_*
 * settings. A version is identified by LEGAL_UPDATED_AT; the rendered text of
 * each version and its hash are kept in legal_document_versions, so an
 * acceptance always points at the exact text the user accepted.
 */
final class LegalDocuments
{
    public const DOCUMENTS = ['termos', 'privacidade'];

    /** English translations (#244), resources/legal/en/. The Portuguese texts prevail and alone define the version. */
    private const ENGLISH = ['termos' => 'terms', 'privacidade' => 'privacy'];

    /**
     * @param  string|null  $templates  directory of the Markdown templates; resources/legal by default.
     *                                  The enterprise edition binds its own texts (#250).
     */
    public function __construct(private readonly ?string $templates = null)
    {
    }

    public function html(string $document, string $locale = 'pt_BR'): string
    {
        $path = $locale === 'en' ? 'en/'.self::ENGLISH[$document].'.md' : "{$document}.md";
        $template = $this->conditionals((string) file_get_contents(rtrim($this->templates ?? resource_path('legal'), '/').'/'.$path));

        return Str::markdown(strtr($template, $this->placeholders($locale)), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Blocks between {{#name}} and {{/name}} are kept only while their feature is on:
     * google_login (#244) when a Google client id is set, even with the login still off.
     */
    private function conditionals(string $template): string
    {
        $flags = ['google_login' => \App\Application\GoogleLogin\GoogleLogin::disclosed()];

        return (string) preg_replace_callback('/\{\{#(\w+)\}\}\n?(.*?)\{\{\/\1\}\}\n?/s', fn (array $m) => ($flags[$m[1]] ?? false) ? $m[2] : '', $template);
    }

    /**
     * Version label of the current texts, which acceptances are recorded against:
     * LEGAL_ACCEPTANCE_VERSION, else LEGAL_UPDATED_AT, else a content hash.
     * LEGAL_ACCEPTANCE_VERSION lets the shown date move for a change that does not
     * need everyone to accept again (#244).
     */
    public function currentVersion(): string
    {
        $label = trim((string) config('legal.acceptance_version', '')) ?: trim((string) config('legal.updated_at', ''));

        return $label !== '' ? $label : 'sha256:'.substr($this->combinedHash(), 0, 16);
    }

    /** Stores the current texts (once per version and content) and returns the row id. */
    public function currentVersionId(): int
    {
        $terms = $this->html('termos');
        $privacy = $this->html('privacidade');
        $termsHash = hash('sha256', $terms);
        $privacyHash = hash('sha256', $privacy);
        $version = $this->currentVersion();

        $id = DB::table('legal_document_versions')
            ->where('version', $version)
            ->where('terms_hash', $termsHash)
            ->where('privacy_hash', $privacyHash)
            ->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        DB::table('legal_document_versions')->insertOrIgnore([
            'version' => $version,
            'terms_hash' => $termsHash,
            'privacy_hash' => $privacyHash,
            'terms_html' => $terms,
            'privacy_html' => $privacy,
            'created_at' => now(),
        ]);

        return (int) DB::table('legal_document_versions')
            ->where('version', $version)->where('terms_hash', $termsHash)->where('privacy_hash', $privacyHash)
            ->value('id');
    }

    private function combinedHash(): string
    {
        return hash('sha256', $this->html('termos')."\0".$this->html('privacidade'));
    }

    /**
     * @return array<string, string>
     */
    public function placeholders(string $locale = 'pt_BR'): array
    {
        if ($locale === 'en') {
            return $this->englishPlaceholders();
        }

        $name = trim((string) config('legal.company_name', ''));
        $id = trim((string) config('legal.company_id', ''));
        $address = trim((string) config('legal.address', ''));
        $email = trim((string) config('legal.contact_email', '')) ?: trim((string) config('mail.from.address', ''));
        $forum = trim((string) config('legal.forum', ''));
        $website = trim((string) config('legal.website', '')) ?: rtrim((string) config('app.url', ''), '/');
        $hosting = trim((string) config('legal.hosting_provider', ''));
        $location = trim((string) config('legal.hosting_location', ''));

        $company = $name !== '' ? $name : 'o operador deste serviço';

        return [
            '{{company}}' => $company,
            '{{Company}}' => Str::ucfirst($company),
            '{{company_id_clause}}' => $id !== '' ? ", inscrito(a) no CNPJ sob o nº {$id}" : '',
            '{{address_clause}}' => $address !== '' ? ", com endereço em {$address}" : '',
            '{{contact_email}}' => $email !== '' ? $email : 'o canal de contato indicado neste site',
            '{{website}}' => $website !== '' ? $website : 'este site',
            '{{hosting_provider}}' => $hosting !== '' ? "provedor de hospedagem ({$hosting})" : 'provedor de hospedagem',
            '{{hosting_location_suffix}}' => $location !== '' ? ", {$location}" : '',
            '{{hosting_location_sentence}}' => $this->hostingLocationSentence($location),
            '{{second_location_items}}' => $this->secondLocationItems(),
            '{{forum_clause}}' => $forum !== ''
                ? "Fica eleito o foro da comarca de {$forum}"
                : 'Fica eleito o foro da comarca do domicílio do Cliente',
        ];
    }

    /**
     * The English texts (#244). LEGAL_HOSTING_LOCATION and LEGAL_FORUM are Portuguese
     * phrases; LEGAL_HOSTING_LOCATION_EN gives the English one, and a location in
     * Brazil or the United States is recognised without it.
     *
     * @return array<string, string>
     */
    private function englishPlaceholders(): array
    {
        $name = trim((string) config('legal.company_name', ''));
        $id = trim((string) config('legal.company_id', ''));
        $address = trim((string) config('legal.address', ''));
        $email = trim((string) config('legal.contact_email', '')) ?: trim((string) config('mail.from.address', ''));
        $forum = trim((string) config('legal.forum', ''));
        $website = trim((string) config('legal.website', '')) ?: rtrim((string) config('app.url', ''), '/');
        $hosting = trim((string) config('legal.hosting_provider', ''));
        $location = trim((string) config('legal.hosting_location', ''));
        $locationEn = $this->englishLocation($location);

        $company = $name !== '' ? $name : 'the operator of this service';

        return [
            '{{company}}' => $company,
            '{{Company}}' => Str::ucfirst($company),
            '{{company_id_clause}}' => $id !== '' ? ", registered under CNPJ No. {$id}" : '',
            '{{address_clause}}' => $address !== '' ? ", with its address at {$address}" : '',
            '{{contact_email}}' => $email !== '' ? $email : 'the contact channel shown on this website',
            '{{website}}' => $website !== '' ? $website : 'this website',
            '{{hosting_provider}}' => $hosting !== '' ? "hosting provider ({$hosting})" : 'hosting provider',
            '{{hosting_location_suffix}}' => $locationEn !== '' ? ", {$locationEn}" : '',
            '{{hosting_location_sentence}}' => match (true) {
                $location === '' => 'The Service may be hosted on servers located outside Brazil.',
                preg_match('/\bbrasil\b/iu', $location) === 1 => 'The Service is hosted on servers located '.($locationEn !== '' ? $locationEn : 'in Brazil').': the application and the database, with the personal data processed in the Service, stay in the country.',
                default => 'The Service is hosted on servers located '.($locationEn !== '' ? $locationEn : 'outside Brazil').'. As a result, the personal data processed in the Service is transferred outside Brazil.',
            },
            '{{second_location_items}}' => $this->secondLocationItems('en'),
            '{{forum_clause}}' => $forum !== ''
                ? "The courts of the district of {$forum} are chosen"
                : "The courts of the district of the Customer's domicile are chosen",
        ];
    }

    private function englishLocation(string $location): string
    {
        $configured = trim((string) config('legal.hosting_location_en', ''));
        if ($configured !== '' || $location === '') {
            return $configured;
        }
        if (preg_match('/\bestados unidos\b/iu', $location) === 1) {
            return 'in the United States';
        }
        if (preg_match('/^(?:no|em) brasil$/iu', $location) === 1) {
            return 'in Brazil';
        }

        return '';
    }

    /**
     * Privacy Policy, section 5 (#163): where the application and the database are.
     * A location in Brazil (for example "no Brasil" or "em São Paulo, no Brasil")
     * means no international transfer for hosting.
     */
    private function hostingLocationSentence(string $location): string
    {
        if ($location === '') {
            return 'O Serviço pode ser hospedado em servidores localizados fora do Brasil.';
        }
        if (preg_match('/\bbrasil\b/iu', $location) === 1) {
            return "O Serviço é hospedado em servidores localizados {$location}: a aplicação e o banco de dados, com os dados pessoais tratados no Serviço, ficam no país.";
        }

        return "O Serviço é hospedado em servidores localizados {$location}. Por isso, os dados pessoais tratados no Serviço são transferidos para fora do Brasil.";
    }

    /**
     * Second-location confirmation (#128): the providers that receive the
     * monitored address when a failure is confirmed, listed only when in use.
     * Each item starts with a newline so it continues the Markdown list.
     */
    private function secondLocationItems(string $locale = 'pt_BR'): string
    {
        $items = '';
        foreach (app(\App\Application\SecondLocation\SecondLocationConfirmer::class)->activeProviderNames() as $name) {
            if ($locale === 'en') {
                if ($name === 'probe') {
                    $host = trim((string) config('second_location.probe.host_label', ''));
                    $items .= "\n- **".($host !== '' ? "our own check server ({$host})" : 'our own check server at another provider').'**, which repeats a failed check to confirm the failure before an incident is opened, and receives the monitored address and the request configured in the monitor (including headers);';
                }
                if ($name === 'globalping') {
                    $items .= "\n- **Globalping** (a public measurement network run by jsDelivr), which may repeat a failed check and receives only the monitored address (URL, or host and port) of monitors without custom headers or body. The results of these measurements are public to anyone who has the measurement identifier;";
                }

                continue;
            }
            if ($name === 'probe') {
                $host = trim((string) config('second_location.probe.host_label', ''));
                $items .= "\n- **".($host !== '' ? "servidor de verificação próprio ({$host})" : 'servidor de verificação próprio em outro provedor').'**, que repete uma verificação que falhou para confirmar a falha antes de abrir um incidente e recebe o endereço monitorado e a requisição configurada no monitor (inclusive cabeçalhos);';
            }
            if ($name === 'globalping') {
                $items .= "\n- **Globalping** (rede pública de medição mantida pelo jsDelivr), que pode repetir uma verificação que falhou e recebe apenas o endereço monitorado (URL ou host e porta) de monitores sem cabeçalhos ou corpo personalizados. Os resultados dessas medições são públicos para quem tiver o identificador da medição;";
            }
        }

        return $items;
    }
}
