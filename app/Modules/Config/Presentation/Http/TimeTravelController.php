<?php

namespace App\Modules\Config\Presentation\Http;

use App\Infrastructure\Time\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class TimeTravelController
{
    public function disable(BusinessClock $clock): RedirectResponse
    {
        $clock->disable(Auth::id() === null ? null : (int) Auth::id());

        return redirect()->route('configuration.time-travel');
    }
}
