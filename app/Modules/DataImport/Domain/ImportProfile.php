<?php

namespace App\Modules\DataImport\Domain;

enum ImportProfile: string
{
    case AgentArchive = 'agent_archive';
    case CustomerFollowup = 'customer_followup';
    case MonthlyDetail = 'monthly_detail';
    case SettlementSummary = 'settlement_summary';
    case Codebook = 'codebook';
    case AgentType = 'reference_agent_type';
    case Institution = 'reference_institution';
    case PolicySystem = 'reference_policy_system';
    case PolicyGrade = 'reference_policy_grade';
    case CommissionRule = 'reference_commission_rule';
    case Agent = 'reference_agent';
    case GradeAssignment = 'reference_grade_assignment';

    public function label(): string
    {
        return match ($this) {
            self::AgentArchive => '代理商档案',
            self::CustomerFollowup => '客户跟进',
            self::MonthlyDetail => '代理商月明细',
            self::SettlementSummary => '代理商月结汇总',
            self::Codebook => '说明/代码表',
            self::AgentType => '代理商类型',
            self::Institution => '机构及机构别名',
            self::PolicySystem => '政策体系',
            self::PolicyGrade => '政策等级',
            self::CommissionRule => '机构费率规则',
            self::Agent => '代理商档案',
            self::GradeAssignment => '代理商等级分配',
        };
    }
}
