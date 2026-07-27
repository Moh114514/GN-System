<?php

namespace App\Modules\DataImport\Domain;

enum ImportBatchStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case NeedsReview = 'needs_review';
    case Validated = 'validated';
    case Committing = 'committing';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Expired = 'expired';
}
