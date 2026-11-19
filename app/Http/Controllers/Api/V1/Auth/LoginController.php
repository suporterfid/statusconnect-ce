<?php

// Adapted from TaskConnect: app/Http/Controllers/Api/V1/Auth/LoginController.php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Credentials first, then the account checks, then the sign-in: refused accounts never
        // fire the Login event, so they get no session and no last_login_at (#248).
        $guard = Auth::guard('web');
        if (! $guard->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'email' => [__('messages.invalid_credentials')],
            ]);
        }

        // Sign-up (#120): unverified emails and organizations awaiting approval cannot sign in yet.
        // #248: neither can users a platform admin blocked.
        $user = $guard->getLastAttempted();
        $refusal = match (true) {
            $user->isBlocked() => 'messages.account_blocked',
            $user->email_verified_at === null => 'messages.email_not_verified',
            \App\Application\Signup\SignupService::awaitingApproval($user) => 'messages.account_pending_approval',
            default => null,
        };
        if ($refusal !== null) {
            throw ValidationException::withMessages(['email' => [__($refusal)]]);
        }

        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($user, ['password' => $credentials['password']]);
        }
        $guard->login($user, $credentials['remember'] ?? false);
        \App\Application\Tenancy\UserSessions::recordMethod($user, 'password');
        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource(Auth::guard('web')->user()),
        ]);
    }
}
