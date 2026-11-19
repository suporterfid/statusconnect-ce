<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\AccountProvisioningService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup: creates the first user (platform admin), its tenant, the
 * production environment and the status page, then signs the user in.
 * Refused for good once any user exists.
 */
class SetupController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'setup_required' => ! User::query()->exists(),
                'restricted' => config('setup.allowed_emails') !== [],
                // Lets the login page link to /cadastro (#120).
                'signup_open' => \App\Application\Signup\SignupService::mode() !== 'closed',
                // Shows "Continue with Google" on the login page (#244).
                'google_login' => \App\Application\GoogleLogin\GoogleLogin::enabled(),
            ],
        ]);
    }

    public function store(Request $request, AccountProvisioningService $provisioning): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
            'org' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'title' => ['nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:en,pt_BR,pt-BR'],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        $allowed = config('setup.allowed_emails');
        if ($allowed === [] && app()->environment('production')) {
            abort(403, __('messages.setup_disabled'));
        }
        if ($allowed !== [] && ! in_array(strtolower(trim($validated['email'])), $allowed, true)) {
            abort(403, __('messages.setup_email_not_allowed'));
        }

        // Serialize concurrent first-run attempts so two requests cannot both see
        // an empty users table. The file/database cache store supports locks.
        $account = Cache::lock('statusconnect:first-run-setup', 10)->block(5, function () use ($validated, $provisioning) {
            if (User::query()->exists()) {
                abort(403, __('messages.setup_already_done'));
            }

            return $provisioning->provision([
                ...$validated,
                'platform_admin' => true,
            ]);
        });

        Auth::guard('web')->login($account->user);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => new UserResource($account->user),
                'tenant' => ['id' => $account->tenant->public_id, 'name' => $account->tenant->name],
                'environment' => ['id' => $account->environment->public_id, 'name' => $account->environment->name],
                'status_page_url' => url('/status/'.$account->statusPageSlug),
            ],
        ], 201);
    }
}
