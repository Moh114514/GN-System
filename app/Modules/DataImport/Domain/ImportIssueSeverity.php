<?php

namespace App\Modules\DataImport\Domain;

enum ImportIssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
