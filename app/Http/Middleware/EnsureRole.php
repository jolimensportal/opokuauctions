<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require one of the given roles.
 *
 * Used on admin capability routes, not on Filament's own panel access — a user
 * who is already inside the panel should still be refused a money action they
 * are not cleared for.
 *
 * Usage: ->middleware('role:owner') or ->middleware('role:ops,owner')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        $allowed = array_map(
            static fn (string $role) => Role::tryFrom($role),
            $roles
        );

        if (! in_array($user->role, array_filter($allowed), true)) {
            abort(403, 'Your role does not permit this action.');
        }

        return $next($request);
    }
}