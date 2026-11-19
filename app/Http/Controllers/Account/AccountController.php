<?php

namespace App\Http\Controllers\Account;

use App\Application\Signup\SignupService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

/**
 * Server-rendered account pages (#120): sign-up, email verification,
 * forgot password and reset password. The operator SPA stays behind login.
 */
final class AccountController extends Controller
{
    public function __construct(private readonly SignupService $signup)
    {
        app()->setLocale((string) config('signup.locale', 'pt_BR'));
    }

    public function signupForm(): View
    {
        abort_if(SignupService::mode() === 'closed', 404);

        return view('account.signup', ['mode' => SignupService::mode()]);
    }

    public function signup(Request $request): RedirectResponse
    {
        abort_if(SignupService::mode() === 'closed', 404);

        // Honeypot: people never see this field. Bots that fill it get the normal answer and nothing is created.
        if (trim((string) $request->input('website', '')) !== '') {
            return redirect()->route('signup.sent');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'organization' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
            'accept_terms' => ['accepted'],
        ]);

        $this->signup->register($validated['name'], $validated['email'], $validated['password'], $validated['organization']);

        return redirect()->route('signup.sent');
    }

    public function signupSent(): View
    {
        return $this->message(__('messages.page_signup_sent_title'), __('messages.page_signup_sent_body'), showResend: true);
    }

    public function verify(Request $request, string $user, string $hash): View
    {
        // Checked here instead of the `signed` middleware so an expired link gets a page with a resend form, not a bare 403.
        $account = $request->hasValidRelativeSignature() ? User::query()->where('public_id', $user)->first() : null;
        if ($account === null || ! $this->signup->verify($account, $hash)) {
            return $this->message(__('messages.page_verify_failed_title'), __('messages.page_verify_failed_body'), showResend: true);
        }

        $pending = SignupService::awaitingApproval($account);

        return $this->message(
            __('messages.page_verified_title'),
            $pending ? __('messages.page_verified_pending_body') : __('messages.page_verified_body'),
            loginLink: ! $pending,
        );
    }

    public function resend(Request $request): View
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::query()->where('email', Str::lower(trim($validated['email'])))->first();
        if ($user !== null && $user->email_verified_at === null) {
            $this->signup->sendVerification($user);
        }

        // Same answer whether or not the address has an account.
        return $this->message(__('messages.page_resend_title'), __('messages.page_resend_body'));
    }

    public function forgotForm(): View
    {
        return view('account.forgot');
    }

    public function forgot(Request $request): View
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::query()->where('email', Str::lower(trim($validated['email'])))->first();

        if ($user !== null) {
            $token = Password::broker()->createToken($user);
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
            try {
                Mail::to($user->email)->send(new AccountMail('reset', __('messages.mail_reset_subject', ['app' => config('app.name')]), [
                    'name' => $user->name,
                    'url' => $url,
                    'minutes' => (int) config('auth.passwords.users.expire', 60),
                ]));
            } catch (Throwable $e) {
                Log::warning('account_mail.failed', ['kind' => 'reset', 'error' => $e->getMessage()]);
            }
        }

        return $this->message(__('messages.page_forgot_sent_title'), __('messages.page_forgot_sent_body'));
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('account.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::broker()->reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                // The reset link reached the mailbox, which proves the address.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __('messages.page_reset_invalid')])->withInput(['email' => $validated['email']]);
        }

        return $this->message(__('messages.page_reset_done_title'), __('messages.page_reset_done_body'), loginLink: true);
    }

    private function message(string $title, string $body, bool $loginLink = false, bool $showResend = false): View
    {
        return view('account.message', compact('title', 'body', 'loginLink', 'showResend'));
    }
}
