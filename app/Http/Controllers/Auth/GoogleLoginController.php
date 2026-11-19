<?php

namespace App\Http\Controllers\Auth;

use App\Application\GoogleLogin\GoogleIdentity;
use App\Application\GoogleLogin\GoogleLogin;
use App\Application\GoogleLogin\GoogleLoginException;
use App\Application\GoogleLogin\GoogleSignInService;
use App\Application\Signup\SignupService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Google\GoogleOidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Sign in with Google (#244). 404 unless GOOGLE_LOGIN_ENABLED and the client
 * id and secret are set.
 *
 * /auth/google/redirect starts the flow with state, nonce and a PKCE verifier
 * kept in the session. /auth/google/callback checks the state, exchanges the
 * code and verifies the ID token, then signs in the linked user. A new address
 * goes to /cadastro/google, which asks for the organization and the terms like
 * the password sign-up, under the same rules. Every refusal returns to the
 * login page with ?google_error=<reason>.
 */
final class GoogleLoginController extends Controller
{
    private const FLOW_KEY = 'google_login.flow';
    private const SIGNUP_KEY = 'google_login.signup';
    private const FLOW_SECONDS = 600;
    private const SIGNUP_SECONDS = 1800;

    public function __construct(
        private readonly GoogleOidcClient $client,
        private readonly GoogleSignInService $signIn,
        private readonly SignupService $signup,
    ) {
        app()->setLocale((string) config('signup.locale', 'pt_BR'));
    }

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(GoogleLogin::enabled(), 404);

        $flow = [
            'state' => Str::random(48),
            'nonce' => Str::random(48),
            'verifier' => Str::random(64),
            'at' => time(),
        ];
        $request->session()->put(self::FLOW_KEY, $flow);

        return redirect()->away($this->client->authorizationUrl($flow['state'], $flow['nonce'], $flow['verifier']));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleLogin::enabled(), 404);

        // One use only: a replayed callback finds no flow.
        $flow = $request->session()->pull(self::FLOW_KEY);

        try {
            if ($request->query('error') !== null) {
                throw new GoogleLoginException($request->query('error') === 'access_denied' ? 'cancelled' : 'failed', 'Google answered error='.Str::limit((string) $request->query('error'), 64));
            }

            $state = $request->query('state');
            $code = $request->query('code');
            if (! is_array($flow) || ! is_string($state) || ! is_string($flow['state'] ?? null) || ! hash_equals($flow['state'], $state)) {
                throw new GoogleLoginException('failed', 'state mismatch');
            }
            if (time() - (int) ($flow['at'] ?? 0) > self::FLOW_SECONDS) {
                throw new GoogleLoginException('failed', 'flow expired');
            }
            if (! is_string($code) || $code === '') {
                throw new GoogleLoginException('failed', 'code missing');
            }

            $identity = $this->client->exchange($code, (string) $flow['verifier'], (string) $flow['nonce']);
            $user = $this->signIn->resolve($identity);

            if ($user === null) {
                if (SignupService::mode() === 'closed') {
                    throw new GoogleLoginException('no_account', 'no account and sign-up closed');
                }
                $request->session()->put(self::SIGNUP_KEY, $identity->toArray() + ['at' => time()]);

                return redirect()->route('signup.google');
            }
        } catch (GoogleLoginException $e) {
            Log::info('google_login.refused', ['reason' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect('/app/login?google_error='.$e->reason);
        }

        Auth::guard('web')->login($user);
        \App\Application\Tenancy\UserSessions::recordMethod($user, 'google');
        $request->session()->regenerate();

        return redirect('/app/dashboard');
    }

    public function signupForm(Request $request): View|RedirectResponse
    {
        abort_unless(GoogleLogin::enabled() && SignupService::mode() !== 'closed', 404);

        $identity = $this->pendingIdentity($request);
        if ($identity === null) {
            return redirect()->route('signup');
        }

        return view('account.google-signup', ['identity' => $identity, 'mode' => SignupService::mode()]);
    }

    public function signup(Request $request): View|RedirectResponse
    {
        abort_unless(GoogleLogin::enabled() && SignupService::mode() !== 'closed', 404);

        $identity = $this->pendingIdentity($request);
        if ($identity === null) {
            return redirect()->route('signup');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required', 'string', 'min:2', 'max:120'],
            'accept_terms' => ['accepted'],
        ]);

        // Same rules as the password sign-up: blocked domains, address taken, per-IP and per-domain limits.
        $user = $this->signup->register($validated['name'], $identity->email, Str::password(40), $validated['organization'], $identity->sub);
        $request->session()->forget(self::SIGNUP_KEY);

        if (SignupService::awaitingApproval($user)) {
            return view('account.message', [
                'title' => __('messages.page_google_signup_pending_title'),
                'body' => __('messages.account_pending_approval'),
                'loginLink' => false,
                'showResend' => false,
            ]);
        }

        Auth::guard('web')->login($user);
        \App\Application\Tenancy\UserSessions::recordMethod($user, 'google');
        $request->session()->regenerate();

        return redirect('/app/dashboard');
    }

    private function pendingIdentity(Request $request): ?GoogleIdentity
    {
        $pending = $request->session()->get(self::SIGNUP_KEY);
        if (! is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::SIGNUP_SECONDS) {
            return null;
        }

        return GoogleIdentity::fromArray($pending);
    }
}
