<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && (
            ! $user->is_active
            || (int) $request->session()->get('auth.session_version', 0) !== (int) $user->session_version
        )) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $user->is_active
                ? '登录会话已失效，请重新登录。'
                : '该账号已停用，请联系超级管理员。';

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
