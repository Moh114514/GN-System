<?php

namespace App\Modules\DataImport\Domain;

enum ImportProfile: string
{
    case AgentArchive = 'agent_archive';
    case CustomerFollowup = 'customer_followup';
    case MonthlyDetail = 'monthly_detail';
    case SettlementSummary = 'settlement_summary';
    case Codebook = 'codebook';

    public function label(): string
    {
        return match ($this) {
            self::AgentArchive => '代理商档案',
            self::CustomerFollowup => '客户跟进',
            self::MonthlyDetail => '代理商月明细',
            self::SettlementSummary => '代理商月结汇总',
            self::Codebook => '说明/代码表',
        };
    }
}
