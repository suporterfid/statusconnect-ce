<?php

namespace App\Http\Views;

use App\Application\Signup\SignupService;
use Illuminate\Contracts\View\View;

/** A plain home page: what this is, sign in, and sign up when it is open (#250). */
final class DefaultLandingPage implements LandingPage
{
    public function render(string $locale): View
    {
        app()->setLocale($locale);

        return view('site.home', [
            'locale' => $locale,
            'plainNav' => true,
            'signupUrl' => SignupService::mode() === 'closed' ? null : url('/cadastro'),
        ]);
    }
}
