<?php

namespace App\Http\Controllers\Admin;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Signing out of the operator console.
 *
 * Filament's own logout invalidates the whole session. That is right when the
 * console and the shop are the same session, and wrong now that they are two
 * guards inside one cookie: invalidating would also sign the buyer out of the
 * shop on the same browser.
 *
 * So this drops the operator's session key, rotates the session id, and leaves
 * everything else alone. The buyer stays signed in.
 */
class ConsoleLogoutController
{
    public function __invoke(Request $request): LogoutResponse
    {
        Filament::auth()->logout();

        $request->session()->regenerate();

        return app(LogoutResponse::class);
    }
}
