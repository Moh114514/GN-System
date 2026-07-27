<?php

namespace App\Modules\DataImport\Domain;

enum ImportProfile: string
{
    case AgentArchive = 'agent_archive';
    case CustomerFollowup = 'customer_followup';
    case MonthlyDetail = 'monthly_detail';
    case SettlementSummary = 'settlement_summary';
    case Codebook = 'codebook';
}
