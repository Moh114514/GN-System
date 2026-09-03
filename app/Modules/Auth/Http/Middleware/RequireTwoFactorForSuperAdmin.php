<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorForSuperAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->isSuperAdmin() && $user->two_factor_confirmed_at === null) {
            return redirect()
                ->route('security.edit')
                ->with('status', __('auth.middleware.two_factor_required'));
        }

        return $next($request);
    }
}
