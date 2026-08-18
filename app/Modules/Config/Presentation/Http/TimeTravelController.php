<?php

namespace App\Modules\Config\Presentation\Http;

use App\Infrastructure\Time\BusinessClock;
use Illuminate\Http\RedirectResponse;

final class TimeTravelController
{
    public function disable(BusinessClock $clock): RedirectResponse
    {
        $clock->disable();

        return redirect()->route('configuration.time-travel');
    }
}
