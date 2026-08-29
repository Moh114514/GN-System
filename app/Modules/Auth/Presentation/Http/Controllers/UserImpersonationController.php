<?php

namespace App\Modules\Auth\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\Services\UserImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UserImpersonationController
{
    public function store(Request $request, UserImpersonationService $impersonation): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target = User::query()->findOrFail((int) $data['user_id']);
        $impersonation->start($target, $request->ip());

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request, UserImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->stop($request->ip());

        return redirect()->route('dashboard');
    }
}
