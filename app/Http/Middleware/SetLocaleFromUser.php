<?php

namespace App\Http\Middleware;

use App\Auth\ApiKeyActor;
use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $locale = $user->preferences?->locale ?? config('app.locale');
        } elseif ($user instanceof ApiKeyActor) {
            // API keys (#156): the client's Accept-Language first, then the language of the
            // person who created the key, then the server default.
            $locale = $this->fromAcceptLanguage($request)
                ?? $user->apiKey->creator?->preferences?->locale
                ?? config('app.locale');
        } else {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }

    private function fromAcceptLanguage(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        foreach ($request->getLanguages() as $language) {
            $language = strtolower($language);
            if (str_starts_with($language, 'pt')) {
                return 'pt_BR';
            }
            if (str_starts_with($language, 'en')) {
                return 'en';
            }
        }

        return null;
    }
}
