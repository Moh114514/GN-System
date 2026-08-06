<?php

namespace App\Modules\DataImport\Domain;

enum ImportIssueStage: string
{
    case FileDetection = 'file_detection';
    case FieldValidation = 'field_validation';
    case Normalization = 'normalization';
    case RelationValidation = 'relation_validation';
    case SummaryValidation = 'summary_validation';
    case DryRun = 'dry_run';
    case Commit = 'commit';
}
