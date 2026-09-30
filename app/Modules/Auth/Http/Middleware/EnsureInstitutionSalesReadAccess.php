<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstitutionSalesReadAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless(
            $user?->isSuperAdmin() || $user?->isBdManager() || $user?->isDirectCustomerManager(),
            403,
        );

        return $next($request);
    }
}
