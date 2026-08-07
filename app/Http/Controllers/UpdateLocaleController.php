<?php

namespace App\Http\Controllers;

use App\Infrastructure\Localization\SupportedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateLocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SupportedLocale::values())],
        ]);

        $locale = SupportedLocale::from((string) $validated['locale']);
        $sessionKey = (string) config('localization.session_key', 'locale');
        $cookieName = (string) config('localization.cookie', 'locale');

        $request->session()->put($sessionKey, $locale->value);

        if ($request->user() !== null && $request->user()->preferred_locale !== $locale->value) {
            $request->user()->forceFill([
                'preferred_locale' => $locale->value,
            ])->save();
        }

        return redirect()->back()->withCookie(cookie(
            name: $cookieName,
            value: $locale->value,
            minutes: (int) config('localization.cookie_lifetime', 60 * 24 * 365),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
