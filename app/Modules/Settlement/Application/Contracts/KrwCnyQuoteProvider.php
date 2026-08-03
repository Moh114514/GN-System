<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\KrwCnyQuoteData;

interface KrwCnyQuoteProvider
{
    public function quote(): KrwCnyQuoteData;
}
