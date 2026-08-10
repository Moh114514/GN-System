<?php

namespace App\Modules\DataImport\Domain;

enum ImportOperationMode: string
{
    case Normal = 'normal';
    case HistoricalCorrection = 'historical_correction';
}
