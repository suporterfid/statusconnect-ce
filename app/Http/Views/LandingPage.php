<?php

namespace App\Http\Views;

use Illuminate\Contracts\View\View;

/**
 * The page at / (pt-BR) and /en (#122, #250).
 *
 * The core shows a plain home page (DefaultLandingPage); the enterprise
 * edition binds the commercial landing page.
 */
interface LandingPage
{
    /** @param  string  $locale  pt_BR or en */
    public function render(string $locale): View;
}
