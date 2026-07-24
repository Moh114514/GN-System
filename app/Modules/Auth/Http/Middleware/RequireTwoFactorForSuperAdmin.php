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

        if ($user?->is_super_admin && $user->two_factor_confirmed_at === null) {
            return redirect()
                ->route('security.edit')
                ->with('status', '请先启用并确认双因素认证，然后再继续。');
        }

        return $next($request);
    }
}
