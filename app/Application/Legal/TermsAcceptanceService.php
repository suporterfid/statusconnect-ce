<?php

namespace App\Application\Legal;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records each user's acceptance of the current Terms of Use and Privacy Policy.
 *
 * Off unless LEGAL_REQUIRE_ACCEPTANCE is true, so self-hosted installs without
 * legal texts are not blocked. When on, a signed-in user who has not accepted
 * the current version (LEGAL_UPDATED_AT) must accept before using the API.
 */
final class TermsAcceptanceService
{
    public function __construct(private readonly LegalDocuments $documents)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('legal.require_acceptance', false);
    }

    public function required(User $user): bool
    {
        return $this->enabled() && ! $this->hasAccepted($user, $this->documents->currentVersion());
    }

    public function hasAccepted(User $user, string $version): bool
    {
        return DB::table('terms_acceptances')->where('user_id', $user->id)->where('version', $version)->exists();
    }

    public function accept(User $user, string $method, ?Request $request = null): void
    {
        $version = $this->documents->currentVersion();
        if ($this->hasAccepted($user, $version)) {
            return;
        }

        DB::table('terms_acceptances')->insert([
            'user_id' => $user->id,
            'email' => $user->email,
            'legal_document_version_id' => $this->documents->currentVersionId(),
            'version' => $version,
            'method' => $method,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
            'accepted_at' => now(),
        ]);
    }

    /** @return array{version: string, accepted_at: string}|null */
    public function latest(User $user): ?array
    {
        $row = DB::table('terms_acceptances')->where('user_id', $user->id)->orderByDesc('accepted_at')->orderByDesc('id')->first(['version', 'accepted_at']);

        return $row === null ? null : ['version' => $row->version, 'accepted_at' => (string) $row->accepted_at];
    }

    /** @return array{required: bool, version: string, terms_url: string, privacy_url: string} */
    public function present(User $user): array
    {
        return [
            'required' => $this->required($user),
            'version' => $this->documents->currentVersion(),
            // English translations (#244) for users who use the app in English.
            'terms_url' => url(app()->getLocale() === 'en' ? '/terms' : '/termos'),
            'privacy_url' => url(app()->getLocale() === 'en' ? '/privacy' : '/privacidade'),
        ];
    }
}
