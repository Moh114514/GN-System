<?php

namespace App\Modules\DataImport\Domain;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Error = 'error';
    case Ignored = 'ignored';
    case DuplicateCandidate = 'duplicate_candidate';
    case Resolved = 'resolved';
}
