<?php

namespace App\Http\Controllers\Public;

use App\Application\Legal\LegalDocuments;
use App\Http\Views\LandingPage;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Public site (#122): the landing page and the legal pages.
 *
 * The landing page is in pt-BR at / and in English at /en; LandingPage
 * renders it (#250). The Terms of Use and Privacy Policy are pt-BR Markdown
 * templates (/termos, /privacidade) with English translations (/terms,
 * /privacy, #244), filled in from config/legal.php; LegalDocuments says
 * where the templates are.
 */
final class SiteController
{
    public function landing(): View
    {
        return $this->renderLanding('pt_BR');
    }

    public function landingEnglish(): View
    {
        return $this->renderLanding('en');
    }

    public function terms(): View
    {
        return $this->renderLegal('termos', 'Termos de Uso');
    }

    public function privacy(): View
    {
        return $this->renderLegal('privacidade', 'Política de Privacidade');
    }

    /** English translations (#244). The Portuguese texts prevail. */
    public function termsEnglish(): View
    {
        return $this->renderLegal('termos', 'Terms of Use', 'en');
    }

    public function privacyEnglish(): View
    {
        return $this->renderLegal('privacidade', 'Privacy Policy', 'en');
    }

    private function renderLanding(string $locale): View
    {
        return app(LandingPage::class)->render($locale);
    }

    private function renderLegal(string $document, string $title, string $locale = 'pt_BR'): View
    {
        app()->setLocale($locale);

        // Wide tables scroll inside their own box instead of the page on narrow screens.
        $html = str_replace(['<table>', '</table>'], ['<div class="table-scroll"><table>', '</table></div>'], app(LegalDocuments::class)->html($document, $locale));

        $paths = ['termos' => ['/termos', '/terms'], 'privacidade' => ['/privacidade', '/privacy']][$document];

        return view('site.legal', [
            'locale' => $locale,
            'title' => $title,
            'html' => $html,
            'updatedAt' => $this->updatedAt($locale),
            // The other language's version of the same document.
            'alternateUrl' => url($locale === 'en' ? $paths[0] : $paths[1]),
        ]);
    }

    private function updatedAt(string $locale = 'pt_BR'): ?string
    {
        $raw = trim((string) config('legal.updated_at', ''));
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->format($locale === 'en' ? 'F j, Y' : 'd/m/Y');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
