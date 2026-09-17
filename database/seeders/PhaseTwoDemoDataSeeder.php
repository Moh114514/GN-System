<?php

namespace Database\Seeders;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Data\AgentImportData;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Data\OrderImportData;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PhaseTwoDemoDataSeeder extends Seeder
{
    private const AGENT_CUSTOMERS = 12;

    /** @var array<int, string> */
    private const PROJECTS = [
        '牙齿贴面',
        '种植牙',
        '皮肤抗衰',
        '热玛吉',
        '干细胞护理',
        '眉眼唇定妆',
        '头皮纹发',
    ];

    /** @var array<int, string> */
    private const STATUS_NAMES = [
        '已预约',
        '已到院',
        '施术结束',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('生产环境禁止生成 Phase 2 模拟数据。');
        }

        $this->call(PhaseTwoReferenceDataSeeder::class);

        DB::transaction(function (): void {
            $institutions = Institution::query()->orderBy('id')->get();
            $agents = $this->seedAgents();
            $this->seedPoliciesAndRates($agents, $institutions->pluck('id')->all());
            $this->seedAgentCustomers($agents, $institutions->pluck('id')->all());
            $this->seedSettlements();
        }, 3);
    }

    /**
     * @return array<int, array{id: int, code: string, index: int}>
     */
    private function seedAgents(): array
    {
        $gateway = app(AgentImportGateway::class);
        $definitions = [
            ['DM01-JG', '机构合作政策', '黄金代理', '旅行社'],
            ['DM02-JG', '机构合作政策', '铂金代理', '商务机构'],
            ['DM03-JG', '机构合作政策', '钻石代理', '高端会所'],
            ['DM04-JG', '机构合作政策', '黄金代理', '跨境服务'],
            ['DM05-JG', '机构合作政策', '铂金代理', '海外机构'],
            ['DM06-GT', '个人合作政策', '普通合伙人', '个人顾问'],
            ['DM07-GT', '个人合作政策', '黄金合伙人', '兼职合伙人'],
            ['DM08-GT', '个人合作政策', '黄金合伙人', '私人银行顾问'],
            ['DM09-GT', '个人合作政策', '普通合伙人', '自媒体博主'],
            ['DM10-KR', '韩国合作政策', '在韩合伙人', '首尔地陪'],
            ['DM11-KR', '韩国合作政策', '在韩合伙人', '留学生合作方'],
            ['DM12-KR', '韩国合作政策', '高级合伙人', '釜山旅行社'],
        ];

        $agents = [];
        foreach ($definitions as $index => [$code, $system, $grade, $role]) {
            $id = $gateway->upsertAgent(new AgentImportData(
                code: $code,
                name: sprintf('【模拟】代理商%02d号', $index + 1),
                businessRole: $role,
                contactName: sprintf('模拟联系人%02d', $index + 1),
                contactValue: sprintf('010-9000-%04d', $index + 1),
                policySystem: $system,
                policyGrade: $grade,
                gradeEffectiveMonth: CarbonImmutable::now()->startOfMonth()->subMonths(6),
                cooperationStartedOn: CarbonImmutable::now()->startOfMonth()->subMonths(12 + $index),
                cooperationStatus: $index === 8 ? 'paused' : 'active',
                notes: '本记录由 PhaseTwoDemoDataSeeder 生成，仅用于开发调试。',
                contractNumber: sprintf('DEMO-%04d', $index + 1),
                contractValidFrom: CarbonImmutable::now()->startOfYear(),
                contractValidUntil: CarbonImmutable::now()->addYear()->endOfYear(),
                importBatchId: null,
            ));
            $agents[] = ['id' => $id, 'code' => $code, 'index' => $index];
        }

        return $agents;
    }

    /**
     * @param  array<int, array{id: int, code: string, index: int}>  $agents
     * @param  array<int, int>  $institutionIds
     */
    private function seedPoliciesAndRates(array $agents, array $institutionIds): void
    {
        $effectiveMonth = CarbonImmutable::now()->startOfMonth();
        $grades = DB::table('policy_grades')->orderBy('id')->get(['id', 'name']);

        foreach ($grades as $gradeIndex => $grade) {
            DB::table('policy_grades')->where('id', $grade->id)->update([
                'sort_order' => ($gradeIndex + 1) * 10,
                'updated_at' => now(),
            ]);

            foreach ($institutionIds as $institutionIndex => $institutionId) {
                DB::table('commission_rules')->updateOrInsert(
                    [
                        'policy_grade_id' => $grade->id,
                        'institution_id' => $institutionId,
                        'effective_month' => $effectiveMonth,
                    ],
                    [
                        'rate_bps' => 500 + ($gradeIndex * 100) + ($institutionIndex * 75),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        foreach (array_slice($agents, 0, 2) as $agentIndex => $agent) {
            DB::table('agent_commission_overrides')->updateOrInsert(
                [
                    'agent_id' => $agent['id'],
                    'institution_id' => $institutionIds[$agentIndex],
                    'effective_from' => $effectiveMonth,
                ],
                [
                    'rate_bps' => 1250 + ($agentIndex * 100),
                    'reason' => '【模拟】重点渠道阶段性特批',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  array<int, array{id: int, code: string, index: int}>  $agents
     * @param  array<int, int>  $institutionIds
     */
    private function seedAgentCustomers(array $agents, array $institutionIds): void
    {
        foreach ($agents as $agent) {
            for ($sequence = 1; $sequence <= self::AGENT_CUSTOMERS; $sequence++) {
                $customerCode = sprintf('%s-%04d', $agent['code'], $sequence);
                $customerId = $this->upsertCustomer(
                    code: $customerCode,
                    name: sprintf('【模拟】渠道客户%02d-%02d', $agent['index'] + 1, $sequence),
                    sourceAgentId: $agent['id'],
                    index: ($agent['index'] * self::AGENT_CUSTOMERS) + $sequence,
                );

                $this->seedCustomerActivity(
                    customerId: $customerId,
                    index: ($agent['index'] * self::AGENT_CUSTOMERS) + $sequence,
                    institutionIds: $institutionIds,
                    agentId: $agent['id'],
                );
            }
        }
    }

    private function upsertCustomer(
        string $code,
        string $name,
        int $sourceAgentId,
        int $index,
    ): int {
        return app(CustomerImportGateway::class)->upsertCustomer(new CustomerImportData(
            code: $code,
            legacyCode: null,
            name: $name,
            gender: $index % 3 === 0 ? '男' : '女',
            birthDate: CarbonImmutable::parse('1975-01-01')->addDays($index * 53),
            sourceAgentId: $sourceAgentId,
            statusName: self::STATUS_NAMES[$index % count(self::STATUS_NAMES)],
            wechatAddedOn: CarbonImmutable::now()->subDays(30 + $index),
            contactValue: sprintf('DEMO-WX-%06d', $index),
            identityDocument: sprintf('DEMO-P%07d', $index),
            projectIntention: self::PROJECTS[$index % count(self::PROJECTS)],
            notes: '本记录由 PhaseTwoDemoDataSeeder 生成，仅用于开发调试。',
            importBatchId: null,
        ));
    }

    /**
     * @param  array<int, int>  $institutionIds
     */
    private function seedCustomerActivity(
        int $customerId,
        int $index,
        array $institutionIds,
        int $agentId,
    ): void {
        $institutionId = $institutionIds[$index % count($institutionIds)];
        $completedOn = CarbonImmutable::now()->startOfMonth()->subMonths($index % 5)->addDays(($index % 20) + 1);
        $amountKrw = 800000 + (($index % 12) * 450000);
        $rateBps = 600 + (($index % 7) * 100);
        $translator = $index % 4 === 0 ? null : sprintf('模拟翻译%02d', ($index % 5) + 1);

        $orderId = app(OrderImportGateway::class)->upsertOrder(new OrderImportData(
            customerId: $customerId,
            institutionId: $institutionId,
            agentId: $agentId,
            projectName: self::PROJECTS[$index % count(self::PROJECTS)],
            amountKrw: $amountKrw,
            scheduledAt: $completedOn->subDays(3)->setTime(10 + ($index % 6), 0),
            completedOn: $completedOn,
            translatorName: $translator,
            notes: 'demo agent order',
            importBatchId: null,
        ));

        $commissionKrw = BigDecimal::of($amountKrw)
            ->multipliedBy($rateBps)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();

        app(SettlementImportGateway::class)->recordCommission(new CommissionImportData(
            orderId: $orderId,
            agentId: $agentId,
            rateBps: $rateBps,
            amountKrw: $commissionKrw,
            ruleSnapshot: [
                'source' => 'demo_data',
                'rate_bps' => $rateBps,
                'effective_month' => $completedOn->startOfMonth()->format('Y-m-d'),
            ],
            overrideReason: $index % 11 === 0 ? '【模拟】人工特批费率' : null,
            importBatchId: null,
        ));

        DB::table('customer_status_histories')->updateOrInsert(
            [
                'customer_id' => $customerId,
                'changed_at' => $completedOn->subDays(7),
            ],
            [
                'to_status_id' => DB::table('customers')->where('id', $customerId)->value('current_status_id'),
                'reason' => '【模拟】生命周期状态变更',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        foreach (['day_1', 'day_7', 'day_30'] as $followupIndex => $type) {
            if ($followupIndex > $index % 3) {
                continue;
            }

            DB::table('followup_records')->updateOrInsert(
                [
                    'customer_id' => $customerId,
                    'order_id' => $orderId,
                    'type' => $type,
                ],
                [
                    'followed_up_on' => $completedOn->addDays([1, 7, 30][$followupIndex]),
                    'content' => sprintf('【模拟】%s 回访：客户反馈良好，继续观察。', $type),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedSettlements(): void
    {
        $gateway = app(SettlementImportGateway::class);
        $exchangeRates = ['214', '218', '222'];

        for ($monthsAgo = 1; $monthsAgo <= 3; $monthsAgo++) {
            $periodStart = CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo);
            $periodEnd = $periodStart->endOfMonth();
            $exchangeRate = $exchangeRates[$monthsAgo - 1];
            $agentIds = DB::table('order_commissions')
                ->join('orders', 'orders.id', '=', 'order_commissions.order_id')
                ->whereBetween('orders.completed_on', [$periodStart, $periodEnd])
                ->distinct()
                ->pluck('order_commissions.agent_id');

            foreach ($agentIds as $agentId) {
                $commissions = DB::table('order_commissions')
                    ->join('orders', 'orders.id', '=', 'order_commissions.order_id')
                    ->where('order_commissions.agent_id', $agentId)
                    ->whereBetween('orders.completed_on', [$periodStart, $periodEnd])
                    ->get([
                        'order_commissions.id',
                        'order_commissions.amount_krw as commission_krw',
                        'order_commissions.rule_snapshot',
                        'orders.amount_krw as consumption_krw',
                    ]);
                $consumptionKrw = (int) $commissions->sum('consumption_krw');
                $commissionKrw = (int) $commissions->sum('commission_krw');
                $payoutFen = BigDecimal::of($commissionKrw)
                    ->dividedBy($exchangeRate, 2, RoundingMode::HalfUp)
                    ->multipliedBy(100)
                    ->toInt();

                $settlementId = $gateway->upsertSettlement(
                    new SettlementImportData(
                        agentId: (int) $agentId,
                        periodStart: $periodStart,
                        periodEnd: $periodEnd,
                        settledOn: $periodEnd->addDays(5),
                        exchangeRateKrwPerCny: $exchangeRate,
                        totalConsumptionKrw: $consumptionKrw,
                        totalCommissionKrw: $commissionKrw,
                        payoutAmountCnyFen: $payoutFen,
                        status: $monthsAgo === 1 ? 'reconciled' : 'paid',
                        importBatchId: null,
                    ),
                );
                DB::table('settlements')->where('id', $settlementId)->update([
                    'snapshot' => json_encode([
                        'source' => 'demo_data',
                        'exchange_rate_krw_per_cny' => $exchangeRate,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

                foreach ($commissions as $commission) {
                    DB::table('settlement_items')->updateOrInsert(
                        [
                            'settlement_id' => $settlementId,
                            'order_commission_id' => $commission->id,
                        ],
                        [
                            'consumption_krw' => $commission->consumption_krw,
                            'commission_krw' => $commission->commission_krw,
                            'rule_snapshot' => $commission->rule_snapshot,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        }
    }
}
