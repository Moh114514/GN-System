<?php

namespace App\Infrastructure\Localization;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale->value);
        Carbon::setLocale($locale->value);

        return $next($request);
    }

    private function resolve(Request $request): SupportedLocale
    {
        $candidates = [];

        if ($request->user() !== null) {
            $candidates[] = $request->user()->preferred_locale;
        }

        if ($request->hasSession()) {
            $candidates[] = $request->session()->get((string) config('localization.session_key', 'locale'));
        }

        $candidates[] = $request->cookie((string) config('localization.cookie', 'locale'));
        $candidates[] = config('app.locale');

        foreach ($candidates as $candidate) {
            $locale = SupportedLocale::fromCandidate($candidate);

            if ($locale !== null) {
                return $locale;
            }
        }

        return SupportedLocale::default();
    }
}
