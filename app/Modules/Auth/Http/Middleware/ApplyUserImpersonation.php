<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Application\Services\UserImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ApplyUserImpersonation
{
    public function __construct(private readonly UserImpersonationService $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->isAvailable()) {
            $this->impersonation->clear();

            return $next($request);
        }

        $authenticatedUser = $request->user();
        if ($authenticatedUser === null) {
            $this->impersonation->clear();

            return $next($request);
        }

        $realUser = $this->impersonation->realUser();
        if ($this->impersonation->hasState()) {
            if ($realUser === null || ! $realUser->is_active || ! $realUser->isSuperAdmin()) {
                $this->impersonation->clear();

                return $next($request);
            }

            // Auth::setUser() must remain request-scoped. This also repairs the
            // guard when the application is reused between requests (for example
            // by a long-running worker or the HTTP test harness).
            if ((int) $realUser->id !== (int) $authenticatedUser->id) {
                Auth::setUser($realUser);
            }
        }

        $target = $this->impersonation->targetUser();
        if ($target !== null) {
            Auth::setUser($target);
        } elseif ($this->impersonation->hasState()) {
            $this->impersonation->clear();
        }

        return $next($request);
    }
}
