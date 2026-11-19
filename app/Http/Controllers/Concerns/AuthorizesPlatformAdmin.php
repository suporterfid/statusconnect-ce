<?php

namespace App\Http\Controllers\Concerns;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\Request;

trait AuthorizesPlatformAdmin
{
    /** The signed-in platform admin; anyone else (API keys included) gets 403. */
    private function platformAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
