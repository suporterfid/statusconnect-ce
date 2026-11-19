<?php

namespace App\Application\Tenancy;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sign-in bookkeeping and ending sessions (#248).
 *
 * Every session remembers the user's session_version from when it started.
 * Ending all sessions bumps the version, so every older session is refused by
 * AuthenticateApiKeyOrSanctum on its next request. That works with file
 * sessions too, which cannot be looked up per user; database sessions are also
 * deleted right away.
 */
final class UserSessions
{
    public const SESSION_KEY = 'auth.session_version';

    public const METHODS = ['password', 'google', 'grandpasson'];

    /** On every Login event: stamp the session and record the time. */
    public static function started(User $user, ?Request $request): void
    {
        if ($request !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, (int) $user->session_version);
        }

        $now = now();
        // Without touching updated_at: signing in is not an edit of the account.
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => $now]);
        $user->forceFill(['last_login_at' => $now])->syncOriginalAttribute('last_login_at');
    }

    /** Records how the user signed in, for the admin detail view. */
    public static function recordMethod(User $user, string $method): void
    {
        $methods = array_values(array_unique([...((array) ($user->sign_in_methods ?? [])), $method]));
        DB::table('users')->where('id', $user->id)->update([
            'last_login_method' => $method,
            'sign_in_methods' => json_encode($methods),
        ]);
        $user->forceFill(['last_login_method' => $method, 'sign_in_methods' => $methods])->syncOriginal();
    }

    /** False when the session predates the user's last "end all sessions". */
    public static function isCurrent(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return true;
        }

        return (int) $request->session()->get(self::SESSION_KEY, 0) === (int) $user->session_version;
    }

    /**
     * Ends every session of the user and revokes their personal tokens.
     *
     * @return array{database_sessions: int, tokens: int}
     */
    public function endAll(User $user): array
    {
        DB::table('users')->where('id', $user->id)->update([
            'session_version' => DB::raw('session_version + 1'),
            // "Remember me" cookies carry this token; a new one invalidates them.
            'remember_token' => Str::random(60),
        ]);
        $user->refresh();

        $sessions = config('session.driver') === 'database'
            ? DB::table('sessions')->where('user_id', $user->id)->delete()
            : 0;

        // Sanctum personal access tokens, where the table exists. StatusConnect's own
        // API keys (sc_*) belong to an environment, not to a person, and are kept.
        $tokens = Schema::hasTable('personal_access_tokens') ? $user->tokens()->delete() : 0;

        return ['database_sessions' => $sessions, 'tokens' => $tokens];
    }
}
