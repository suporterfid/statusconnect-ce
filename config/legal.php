<?php

/*
 * Data shown in the Terms of Use (/termos) and Privacy Policy (/privacidade) (#122).
 *
 * Every value defaults to empty so each operator of an instance fills in their
 * own. The pages render with neutral wording while a value is empty.
 */
return [
    // Legal name of the company that operates this instance.
    'company_name' => env('LEGAL_COMPANY_NAME', ''),
    // Company registration number (CNPJ in Brazil).
    'company_id' => env('LEGAL_COMPANY_ID', ''),
    'address' => env('LEGAL_ADDRESS', ''),
    // Contact and data protection officer (encarregado) e-mail. Empty falls back to mail.from.address.
    'contact_email' => env('LEGAL_CONTACT_EMAIL', ''),
    // Court district chosen in the Terms of Use (comarca), e.g. "Campinas/SP".
    'forum' => env('LEGAL_FORUM', ''),
    // Public website of the operator. Empty falls back to app.url.
    'website' => env('LEGAL_WEBSITE', ''),
    // Date shown as "Última atualização", e.g. 2026-11-09.
    'updated_at' => env('LEGAL_UPDATED_AT', ''),
    // Version users accept (#244). Empty = LEGAL_UPDATED_AT. Keep the previous value here when the
    // shown date moves for a change that does not need everyone to accept again.
    'acceptance_version' => env('LEGAL_ACCEPTANCE_VERSION', ''),
    // Hosting provider named in the Privacy Policy as a data processor (operador).
    'hosting_provider' => env('LEGAL_HOSTING_PROVIDER', ''),
    // Where the server is, as a Portuguese phrase that completes "hospedado ...", e.g. "nos Estados Unidos" or "no Brasil".
    'hosting_location' => env('LEGAL_HOSTING_LOCATION', ''),
    // The same in English for /terms and /privacy (#244), e.g. "in the United States". Empty: recognised
    // for Brazil and the United States, otherwise the English texts say "outside Brazil".
    'hosting_location_en' => env('LEGAL_HOSTING_LOCATION_EN', ''),
    // Every signed-in user must accept the current texts (version = LEGAL_UPDATED_AT) before using the app.
    'require_acceptance' => (bool) env('LEGAL_REQUIRE_ACCEPTANCE', false),
];
