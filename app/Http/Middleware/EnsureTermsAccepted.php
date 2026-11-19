<?php

namespace App\Http\Middleware;

use App\Application\Legal\TermsAcceptanceService;
use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in users must accept the current Terms of Use and Privacy Policy
 * before using the API. API keys and machine actors are not people and pass.
 */
final class EnsureTermsAccepted
{
    public function __construct(private readonly TermsAcceptanceService $terms)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $this->terms->required($user)) {
            return response()->json([
                'message' => __('messages.terms_acceptance_required'),
                'code' => 'terms_acceptance_required',
            ], 403);
        }

        return $next($request);
    }
}
