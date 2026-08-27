<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Data\AgentImportData;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Deterministic local development scenario.
 *
 * This seeder intentionally uses stable business keys and marks every generated
 * record with the 【模拟】 marker. It is safe to run repeatedly in a local or
 * test database, but must never be run against production.
 */
class DevelopmentScenarioSeeder extends Seeder
{
    private const MARKER = '【模拟】';

    private const AUDIT_BATCH = '00000000-0000-4000-8000-000000000099';

    /** @var array<int, string> */
    private const PROJECTS = ['牙齿贴面', '种植牙', '皮肤抗衰', '热玛吉', '干细胞护理', '眉眼定制', '头皮纹发'];

    /** @var array<int, string> */
    private const LANGUAGES = ['中文', '韩文', '中韩双语'];

    /**
     * @var array<int, array{code: string, type: string, role: string, status: string}>
     */
    private const AGENTS = [
        ['code' => 'DEV01-JG', 'type' => 'JG', 'role' => '高客单机构合作', 'status' => 'active'],
        ['code' => 'DEV02-JG', 'type' => 'JG', 'role' => '大型机构合作', 'status' => 'active'],
        ['code' => 'DEV03-JG', 'type' => 'JG', 'role' => '高端会员机构', 'status' => 'active'],
        ['code' => 'DEV04-JG', 'type' => 'JG', 'role' => '跨境服务机构', 'status' => 'active'],
        ['code' => 'DEV05-JG', 'type' => 'JG', 'role' => '一般机构合作', 'status' => 'active'],
        ['code' => 'DEV06-GT', 'type' => 'GT', 'role' => '个人顾问', 'status' => 'active'],
        ['code' => 'DEV07-GT', 'type' => 'GT', 'role' => '兼职合伙人', 'status' => 'active'],
        ['code' => 'DEV08-GT', 'type' => 'GT', 'role' => '私域顾问', 'status' => 'paused'],
        ['code' => 'DEV09-GT', 'type' => 'GT', 'role' => '普通合伙人', 'status' => 'active'],
        ['code' => 'DEV10-KR', 'type' => 'KR', 'role' => '首尔合作方', 'status' => 'active'],
        ['code' => 'DEV11-KR', 'type' => 'KR', 'role' => '留学生合作方', 'status' => 'active'],
        ['code' => 'DEV12-KR', 'type' => 'KR', 'role' => '釜山合作方', 'status' => 'active'],
        ['code' => 'DEV13-KR', 'type' => 'KR', 'role' => '高端韩国合作方', 'status' => 'active'],
        ['code' => 'DEV14-JG', 'type' => 'JG', 'role' => '待优化机构', 'status' => 'terminated'],
        ['code' => 'DEV15-GT', 'type' => 'GT', 'role' => '低活跃顾问', 'status' => 'active'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('生产环境禁止生成开发模拟数据。');
        }

        $this->call(PhaseTwoReferenceDataSeeder::class);

        DB::transaction(function (): void {
            $users = $this->seedUsers();
            $groups = $this->seedBusinessGroups($users['admin@example.test']);
            $this->seedReferenceExtensions($users['admin@example.test']);
            $agents = $this->seedAgents($users['admin@example.test'], $groups);
            $this->seedPoliciesAndRates($agents, $users['admin@example.test']);
            $customers = $this->seedCustomers($agents, $users, $groups);
            $orders = $this->seedOrders($agents, $customers, $users, $groups);
            $this->seedFollowups($customers, $orders, $users);
            $this->seedReminders($customers, $orders, $users);
            $this->seedImports($users['admin@example.test']);
            $this->seedSettlements($agents, $users['admin@example.test']);
            $this->seedBdQuarterlyCommissions($groups, $users);
            $this->seedReports($users);
            $this->seedAuditLog($users['admin@example.test']);
        }, 3);
    }

    /** @return array<string, int> */
    private function seedUsers(): array
    {
        $definitions = [
            ['admin@example.test', '超级管理员', 'super_admin', true, true, 'zh_CN'],
            ['bd.a@example.test', 'BD经理 A', 'bd_manager', false, true, 'zh_CN'],
            ['bd.b@example.test', 'BD经理 B', 'bd_manager', false, true, 'zh_CN'],
            ['service.a1@example.test', '客服 A1', 'customer_service', false, true, 'zh_CN'],
            ['service.a2@example.test', '客服 A2', 'customer_service', false, true, 'ko_KR'],
            ['service.a3@example.test', '客服 A3', 'customer_service', false, true, 'zh_CN'],
            ['service.b1@example.test', '客服 B1', 'customer_service', false, true, 'zh_CN'],
            ['service.b2@example.test', '客服 B2', 'customer_service', false, true, 'ko_KR'],
            ['disabled@example.test', '停用用户', 'customer_service', false, false, 'zh_CN'],
            ['test@example.com', '通用测试用户', 'customer_service', false, true, 'zh_CN'],
        ];

        /** @var array<string, int> $ids */
        $ids = [];
        foreach ($definitions as [$email, $name, $role, $isSuperAdmin, $active, $locale]) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => self::MARKER.$name,
                    'password' => 'password',
                    'email_verified_at' => now(),
                    'is_super_admin' => $isSuperAdmin,
                    'role' => $role,
                    'is_active' => $active,
                    'preferred_locale' => $locale,
                    'invitation_status' => 'accepted',
                    'disabled_at' => $active ? null : now()->subDays(15),
                    'disabled_by' => null,
                ],
            );
            $ids[$email] = (int) $user->id;
        }

        User::query()->whereKey($ids['disabled@example.test'])->update([
            'disabled_by' => $ids['admin@example.test'],
        ]);

        return $ids;
    }

    /** @return array<string, int> */
    private function seedBusinessGroups(int $adminId): array
    {
        $groups = [];
        foreach ([['BD-A', '业务组 A'], ['BD-B', '业务组 B']] as [$code, $name]) {
            $groups[$code] = $this->upsertId('business_groups',
                ['code' => $code],
                ['name' => self::MARKER.$name, 'is_active' => true, 'created_by' => $adminId, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $memberships = [
            ['BD-A', 'bd.a@example.test', 'bd_manager'],
            ['BD-A', 'service.a1@example.test', 'customer_service'],
            ['BD-A', 'service.a2@example.test', 'customer_service'],
            ['BD-A', 'service.a3@example.test', 'customer_service'],
            ['BD-B', 'bd.b@example.test', 'bd_manager'],
            ['BD-B', 'service.b1@example.test', 'customer_service'],
            ['BD-B', 'service.b2@example.test', 'customer_service'],
        ];
        $userIds = User::query()->whereIn('email', array_column($memberships, 1))->pluck('id', 'email');
        foreach ($memberships as [$groupCode, $email, $role]) {
            $groupId = $groups[$groupCode];
            DB::table('business_group_memberships')->updateOrInsert(
                ['business_group_id' => $groupId, 'user_id' => $userIds[$email], 'effective_from' => now()->startOfYear()->toDateString()],
                [
                    'member_role' => $role,
                    'effective_until' => null,
                    'assigned_by' => $adminId,
                    'reason' => self::MARKER.'开发场景业务组成员初始化',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $groups;
    }

    /** @return array{projects: array<string, int>, languages: array<string, int>} */
    private function seedReferenceExtensions(int $adminId): array
    {
        $projects = [];
        foreach (self::PROJECTS as $project) {
            $projects[$project] = $this->upsertId('config_dictionary_items',
                ['type' => 'treatment_project', 'code' => 'dev_'.md5($project)],
                ['name' => self::MARKER.$project, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $languages = [];
        foreach (self::LANGUAGES as $language) {
            $languages[$language] = $this->upsertId('config_dictionary_items',
                ['type' => 'translator_language', 'code' => 'dev_'.md5($language)],
                ['name' => self::MARKER.$language, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        for ($monthsAgo = 0; $monthsAgo <= 5; $monthsAgo++) {
            DB::table('settlement_configurations')->updateOrInsert(
                ['effective_from' => CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo)->toDateString()],
                [
                    'boundary_day' => 1,
                    'generation_day' => 5,
                    'trigger_time' => '09:00:00',
                    'timezone' => 'Asia/Shanghai',
                    'created_by' => $adminId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        foreach (Institution::query()->orderBy('id')->get() as $institution) {
            DB::table('institution_form_templates')->updateOrInsert(
                ['institution_id' => $institution->id, 'template_key' => 'development_return', 'version' => 1],
                [
                    'columns' => json_encode(['customer_code', 'occurred_on', 'project_name', 'amount_krw', 'translator_name'], JSON_THROW_ON_ERROR),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return ['projects' => $projects, 'languages' => $languages];
    }

    /**
     * @param  array<string, int>  $groups
     * @return array<int, array{id: int, code: string, group: string, bd: int}>
     */
    private function seedAgents(int $adminId, array $groups): array
    {
        $gateway = app(AgentImportGateway::class);
        $agents = [];
        foreach (self::AGENTS as $index => $definition) {
            $id = $gateway->upsertAgent(new AgentImportData(
                code: $definition['code'],
                name: self::MARKER.'代理商 '.substr($definition['code'], 3, 2),
                businessRole: $definition['role'],
                contactName: self::MARKER.'联系人 '.($index + 1),
                contactValue: '010-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                policySystem: self::MARKER.($definition['type'] === 'JG' ? '机构合作政策' : ($definition['type'] === 'GT' ? '个人合作政策' : '韩国合作政策')),
                policyGrade: self::MARKER.['普通等级', '黄金等级', '钻石等级'][$index % 3],
                gradeEffectiveMonth: CarbonImmutable::now()->startOfMonth()->subMonths(6),
                cooperationStartedOn: CarbonImmutable::now()->startOfMonth()->subMonths(12 + $index),
                cooperationStatus: $definition['status'],
                notes: self::MARKER.'DevelopmentScenarioSeeder 生成，仅用于开发调试。',
                contractNumber: 'DEV-CONTRACT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                contractValidFrom: CarbonImmutable::now()->startOfYear(),
                contractValidUntil: CarbonImmutable::now()->addYear()->endOfYear(),
                importBatchId: null,
            ));
            $groupCode = $index < 8 ? 'BD-A' : 'BD-B';
            $bdEmail = $groupCode === 'BD-A' ? 'bd.a@example.test' : 'bd.b@example.test';
            $bdId = (int) User::query()->where('email', $bdEmail)->value('id');
            DB::table('agent_business_group_assignments')->updateOrInsert(
                ['agent_id' => $id, 'effective_from' => now()->startOfYear()->toDateString()],
                [
                    'business_group_id' => $groups[$groupCode],
                    'effective_until' => null,
                    'assigned_by' => $adminId,
                    'reason' => self::MARKER.'代理商业务组归属初始化',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $agents[] = ['id' => $id, 'code' => $definition['code'], 'group' => $groupCode, 'bd' => $bdId];
        }

        return $agents;
    }

    /** @param array<int, array{id: int, code: string, group: string, bd: int}> $agents */
    private function seedPoliciesAndRates(array $agents, int $adminId): void
    {
        $institutions = Institution::query()->orderBy('id')->pluck('id')->all();
        $grades = DB::table('policy_grades')->orderBy('id')->get(['id', 'name']);
        foreach ($grades as $gradeIndex => $grade) {
            DB::table('policy_grades')->where('id', $grade->id)->update([
                'monthly_threshold_krw' => ($gradeIndex + 1) * 10000000,
                'sort_order' => ($gradeIndex + 1) * 10,
                'updated_at' => now(),
            ]);
            for ($monthsAgo = 0; $monthsAgo <= 5; $monthsAgo++) {
                $month = now()->startOfMonth()->subMonths($monthsAgo)->toDateString();
                foreach ($institutions as $institutionIndex => $institutionId) {
                    DB::table('commission_rules')->updateOrInsert(
                        ['policy_grade_id' => $grade->id, 'institution_id' => $institutionId, 'effective_month' => $month],
                        [
                            'rate_bps' => 500 + ($gradeIndex * 100) + ($institutionIndex * 75),
                            'is_active' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            }
        }

        foreach (array_slice($agents, 0, 2) as $index => $agent) {
            DB::table('agent_commission_overrides')->updateOrInsert(
                ['agent_id' => $agent['id'], 'institution_id' => $institutions[$index], 'effective_from' => now()->startOfMonth()->toDateString()],
                [
                    'rate_bps' => 1250 + ($index * 100),
                    'reason' => self::MARKER.'重点渠道阶段性特批费率',
                    'approved_by' => $adminId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  array<int, array{id: int, code: string, group: string, bd: int}>  $agents
     * @param  array<string, int>  $users
     * @param  array<string, int>  $groups
     * @return array<int, array{id: int, agent: int, owner: int|null, status: string, group: string}>
     */
    private function seedCustomers(array $agents, array $users, array $groups): array
    {
        $statuses = DB::table('customer_statuses')->pluck('name', 'key')->all();
        $customers = [];
        for ($index = 1; $index <= 200; $index++) {
            $agent = $agents[($index - 1) % count($agents)];
            $statusKey = $index <= 50 ? 'booked' : ($index <= 100 ? 'arrived' : 'treatment_completed');
            $group = $agent['group'];
            $ownerPool = $group === 'BD-A'
                ? [$users['service.a1@example.test'], $users['service.a2@example.test'], $users['service.a3@example.test']]
                : [$users['service.b1@example.test'], $users['service.b2@example.test']];
            $ownerId = $index % 17 === 0 ? null : $ownerPool[$index % count($ownerPool)];
            $baseDate = CarbonImmutable::now()->startOfDay()->subDays(240 - ($index % 180));
            $customerId = app(CustomerImportGateway::class)->upsertCustomer(new CustomerImportData(
                code: sprintf('DEV-CUST-%04d', $index),
                legacyCode: null,
                name: $index === 1 ? self::MARKER.'边界测试超长姓名客户（中韩 혼합）' : self::MARKER.'客户 '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                gender: $index % 3 === 0 ? '男' : '女',
                birthDate: CarbonImmutable::parse('1975-01-01')->addDays($index * 41),
                sourceAgentId: $agent['id'],
                statusName: $statuses[$statusKey],
                wechatAddedOn: $baseDate,
                contactValue: 'DEV-WX-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                identityDocument: 'DEV-P-'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                projectIntention: self::PROJECTS[($index - 1) % count(self::PROJECTS)],
                notes: self::MARKER.'DevelopmentScenarioSeeder 客户场景；用于列表、搜索、权限与生命周期测试。',
                importBatchId: null,
            ));

            $arrivedAt = $statusKey === 'booked' ? null : $baseDate->addDays(14);
            $completedAt = $statusKey === 'treatment_completed' ? $baseDate->addDays(21) : null;
            DB::table('customers')->where('id', $customerId)->update([
                'owner_id' => $ownerId,
                'arrived_at' => $arrivedAt,
                'treatment_completed_at' => $completedAt,
                'updated_at' => now(),
            ]);

            $statusOrder = ['booked', 'arrived', 'treatment_completed'];
            $statusIndex = array_search($statusKey, $statusOrder, true);
            for ($transition = 0; $transition <= $statusIndex; $transition++) {
                $changedAt = $baseDate->addDays($transition * 7);
                DB::table('customer_status_histories')->updateOrInsert(
                    ['customer_id' => $customerId, 'changed_at' => $changedAt],
                    [
                        'from_status_id' => $transition === 0 ? null : DB::table('customer_statuses')->where('key', $statusOrder[$transition - 1])->value('id'),
                        'to_status_id' => DB::table('customer_statuses')->where('key', $statusOrder[$transition])->value('id'),
                        'changed_by' => $ownerId ?? $users['admin@example.test'],
                        'reason' => self::MARKER.'客户生命周期状态历史',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
            DB::table('customer_owner_histories')->updateOrInsert(
                ['customer_id' => $customerId, 'effective_at' => $baseDate],
                [
                    'business_group_id' => $groups[$group],
                    'from_owner_id' => null,
                    'to_owner_id' => $ownerId,
                    'source' => 'initial',
                    'transfer_request_id' => null,
                    'changed_by' => $users['admin@example.test'],
                    'reason' => self::MARKER.'客户初始负责人归属',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $customers[$index] = ['id' => $customerId, 'agent' => $agent['id'], 'owner' => $ownerId, 'status' => $statusKey, 'group' => $group];
        }

        return $customers;
    }

    /**
     * @param  array<int, array{id: int, code: string, group: string, bd: int}>  $agents
     * @param  array<int, array{id: int, agent: int, owner: int|null, status: string, group: string}>  $customers
     * @param  array<string, int>  $users
     * @param  array<string, int>  $groups
     * @return array<int, array{id: int, customer: int, agent: int, date: string|null, commission: int|null}>
     */
    private function seedOrders(array $agents, array $customers, array $users, array $groups): array
    {
        $institutions = Institution::query()->orderBy('id')->pluck('id')->all();
        $projects = DB::table('config_dictionary_items')->where('type', 'treatment_project')->where('code', 'like', 'dev_%')->orderBy('id')->pluck('id')->all();
        $languages = DB::table('config_dictionary_items')->where('type', 'translator_language')->where('code', 'like', 'dev_%')->orderBy('id')->pluck('id')->all();
        $orders = [];
        for ($index = 1; $index <= 250; $index++) {
            $customerNumber = 31 + (($index - 1) % 170);
            $customer = $customers[$customerNumber];
            $agent = $agents[($customerNumber - 1) % count($agents)];
            $institutionId = $institutions[($index - 1) % count($institutions)];
            $marker = self::MARKER.'DevelopmentScenarioSeeder订单-'.$index;
            $isPending = $index <= 25;
            $isCancelled = $index > 25 && $index <= 35;
            $occurredOn = null;
            $completedAt = null;
            if (! $isPending && ! $isCancelled) {
                $occurredOn = $index === 36
                    ? CarbonImmutable::now()->startOfMonth()->subDay()
                    : ($index === 37 ? CarbonImmutable::now()->startOfMonth() : CarbonImmutable::now()->startOfMonth()->subMonths(($index - 37) % 6)->addDays(1 + ($index % 20)));
                $completedAt = $occurredOn->setTime(10 + ($index % 7), ($index % 4) * 15);
            }
            $amount = $index === 36 ? 0 : ($index === 37 ? 99999999999 : 800000 + (($index * 137) % 12000000));
            $scheduledAt = $isPending
                ? CarbonImmutable::now()->addDays(1 + ($index % 25))->setTime(10 + ($index % 6), 0)
                : (($occurredOn ?? CarbonImmutable::now()->subDays(20))->subDays(3)->setTime(10 + ($index % 6), 0));
            $appointmentKey = ['customer_id' => $customer['id'], 'notes' => $marker];
            DB::table('appointments')->updateOrInsert(
                $appointmentKey,
                [
                    'institution_id' => $institutionId,
                    'scheduled_at' => $scheduledAt,
                    'treatment_project_id' => $projects[($index - 1) % count($projects)],
                    'treatment_project_snapshot' => self::MARKER.self::PROJECTS[($index - 1) % count(self::PROJECTS)],
                    'translator_language_id' => $languages[($index - 1) % count($languages)],
                    'translator_language_snapshot' => self::MARKER.self::LANGUAGES[($index - 1) % count(self::LANGUAGES)],
                    'translator_name' => $index % 4 === 0 ? null : self::MARKER.'翻译员 '.(($index % 5) + 1),
                    'owner_id' => $customer['owner'],
                    'status' => $isPending ? 'scheduled' : ($isCancelled ? 'cancelled' : 'completed'),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $appointmentId = DB::table('appointments')->where($appointmentKey)->value('id');
            $orderValues = [
                'customer_id' => $customer['id'],
                'institution_id' => $institutionId,
                'appointment_id' => $appointmentId,
                'agent_id' => $agent['id'],
                'project_name' => self::MARKER.self::PROJECTS[($index - 1) % count(self::PROJECTS)],
                'amount_krw' => $amount,
                'completed_on' => $occurredOn?->toDateString(),
                'occurred_on' => $occurredOn?->toDateString(),
                'completed_at' => $completedAt,
                'completion_precision' => 'date',
                'treatment_project_id' => $projects[($index - 1) % count($projects)],
                'treatment_project_snapshot' => self::MARKER.self::PROJECTS[($index - 1) % count(self::PROJECTS)],
                'translator_language_id' => $languages[($index - 1) % count($languages)],
                'translator_language_snapshot' => self::MARKER.self::LANGUAGES[($index - 1) % count(self::LANGUAGES)],
                'translator_name' => $index % 4 === 0 ? null : self::MARKER.'翻译员 '.(($index % 5) + 1),
                'owner_id' => $customer['owner'],
                'status' => $isPending ? 'pending' : ($isCancelled ? 'cancelled' : 'completed'),
                'record_status' => 'active',
                'business_attribution_snapshot' => json_encode([
                    'business_group' => ['code' => $customer['group'], 'id' => $groups[$customer['group']], 'bd_manager' => ['user_id' => $agent['group'] === 'BD-A' ? $users['bd.a@example.test'] : $users['bd.b@example.test']]],
                    'agent' => ['id' => $agent['id'], 'code' => $agent['code']],
                    'marker' => self::MARKER,
                    'occurred_on' => $occurredOn?->toDateString(),
                ], JSON_THROW_ON_ERROR),
                'notes' => $marker.($isCancelled ? '；取消订单场景' : ($isPending ? '；未来预约场景' : '；完成订单场景')),
                'cancelled_at' => $isCancelled ? now()->subDays(5 + $index) : null,
                'cancelled_by' => $isCancelled ? $users['admin@example.test'] : null,
                'cancellation_reason' => $isCancelled ? self::MARKER.'客户主动取消，保留生命周期记录' : null,
                'updated_at' => now(),
                'created_at' => now(),
            ];
            DB::table('orders')->updateOrInsert(['customer_id' => $customer['id'], 'notes' => $orderValues['notes']], $orderValues);
            $orderId = DB::table('orders')->where(['customer_id' => $customer['id'], 'notes' => $orderValues['notes']])->value('id');
            DB::table('order_items')->updateOrInsert(
                ['order_id' => $orderId, 'project_snapshot' => $orderValues['project_name']],
                [
                    'treatment_project_id' => $orderValues['treatment_project_id'],
                    'specification' => self::MARKER.'标准项目规格',
                    'quantity' => 1,
                    'unit_price_krw' => $amount,
                    'amount_krw' => $amount,
                    'notes' => self::MARKER.'订单明细',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $commission = null;
            if ($occurredOn !== null) {
                $rateBps = 600 + (($index + $agent['id']) % 7) * 100;
                $commission = intdiv(($amount * $rateBps) + 5000, 10000);
                app(SettlementImportGateway::class)->recordCommission(new CommissionImportData(
                    orderId: (int) $orderId,
                    agentId: $agent['id'],
                    rateBps: $rateBps,
                    amountKrw: $commission,
                    ruleSnapshot: ['source' => 'development_scenario', 'marker' => self::MARKER, 'rate_bps' => $rateBps, 'effective_month' => $occurredOn->startOfMonth()->toDateString()],
                    overrideReason: $index % 11 === 0 ? self::MARKER.'人工特批费率' : null,
                    importBatchId: null,
                ));
            }
            $orders[$index] = ['id' => (int) $orderId, 'customer' => $customer['id'], 'agent' => $agent['id'], 'date' => $occurredOn?->toDateString(), 'commission' => $commission];
        }

        return $orders;
    }

    /**
     * @param  array<int, array{id: int, agent: int, owner: int|null, status: string, group: string}>  $customers
     * @param  array<int, array{id: int, customer: int, agent: int, date: string|null, commission: int|null}>  $orders
     * @param  array<string, int>  $users
     */
    private function seedFollowups(array $customers, array $orders, array $users): void
    {
        $orderByCustomer = [];
        foreach ($orders as $order) {
            $orderByCustomer[$order['customer']] ??= $order['id'];
        }
        for ($customerNumber = 31; $customerNumber <= 100; $customerNumber++) {
            for ($step = 1; $step <= 3; $step++) {
                $customer = $customers[$customerNumber];
                $content = self::MARKER.'客户回访-'.$customerNumber.'-'.$step.'：客户反馈良好，继续观察。';
                DB::table('followup_records')->updateOrInsert(
                    ['customer_id' => $customer['id'], 'type' => 'day_'.$step, 'content' => $content],
                    [
                        'order_id' => $orderByCustomer[$customer['id']] ?? null,
                        'followed_up_on' => now()->subDays(45 - (($customerNumber + $step) % 30))->toDateString(),
                        'owner_id' => $customer['owner'] ?? $users['admin@example.test'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
        for ($customerNumber = 101; $customerNumber <= 110; $customerNumber++) {
            $customer = $customers[$customerNumber];
            DB::table('followup_records')->updateOrInsert(
                ['customer_id' => $customer['id'], 'type' => 'manual', 'content' => self::MARKER.'边界场景仅一条回访记录-'.$customerNumber],
                ['order_id' => $orderByCustomer[$customer['id']] ?? null, 'followed_up_on' => now()->subDays(2)->toDateString(), 'owner_id' => $customer['owner'] ?? $users['admin@example.test'], 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * @param  array<int, array{id: int, agent: int, owner: int|null, status: string, group: string}>  $customers
     * @param  array<int, array{id: int, customer: int, agent: int, date: string|null, commission: int|null}>  $orders
     * @param  array<string, int>  $users
     */
    private function seedReminders(array $customers, array $orders, array $users): void
    {
        $ruleIds = [];
        foreach ([
            ['dev_appointment', '预约前提醒', 'date_offset'],
            ['dev_arrival', '到院接待提醒', 'status_change'],
            ['dev_followup', '回访提醒', 'fixed_cycle'],
            ['dev_birthday', '生日关怀提醒', 'birthday'],
            ['dev_overdue', '逾期处理提醒', 'date_offset'],
        ] as [$key, $name, $trigger]) {
            DB::table('reminder_rules')->updateOrInsert(
                ['name' => self::MARKER.$name],
                [
                    'trigger_type' => $trigger,
                    'trigger_config' => json_encode(['development_key' => $key, 'interval_days' => 7], JSON_THROW_ON_ERROR),
                    'scope_type' => 'all_customers',
                    'scope_config' => json_encode([], JSON_THROW_ON_ERROR),
                    'title' => self::MARKER.$name,
                    'suggestion' => self::MARKER.'请及时处理该提醒。',
                    'priority' => 2,
                    'is_active' => true,
                    'is_system' => false,
                    'created_by' => $users['admin@example.test'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $ruleIds[$key] = (int) DB::table('reminder_rules')->where('name', self::MARKER.$name)->value('id');
        }

        $templateIds = [];
        foreach ([['dev_appointment', '预约确认模板', 'appointment'], ['dev_followup', '客户回访模板', 'followup'], ['dev_holiday', '节假日关怀模板', 'holiday']] as [$key, $name, $trigger]) {
            DB::table('reminder_templates')->updateOrInsert(
                ['system_key' => $key],
                [
                    'name' => self::MARKER.$name,
                    'title' => self::MARKER.$name,
                    'suggestion' => self::MARKER.'请按客户情况完成处理。',
                    'default_trigger_type' => $trigger,
                    'default_trigger_config' => json_encode(['development_key' => $key], JSON_THROW_ON_ERROR),
                    'is_system' => false,
                    'is_active' => true,
                    'owner_id' => $users['admin@example.test'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $templateIds[$key] = (int) DB::table('reminder_templates')->where('system_key', $key)->value('id');
        }

        for ($index = 1; $index <= 70; $index++) {
            $customerNumber = 31 + (($index - 1) % 70);
            $customer = $customers[$customerNumber];
            $status = $index <= 10 ? 'completed' : ($index <= 20 ? 'snoozed' : 'pending');
            $scenarioDay = CarbonImmutable::now()->startOfDay();
            $dueAt = $status === 'completed' ? $scenarioDay->subDays(3 + $index) : ($status === 'snoozed' ? $scenarioDay->addDays(2) : $scenarioDay->subDays($index % 12));
            $dedupeKey = hash('sha256', self::MARKER.'reminder-'.$index);
            DB::table('reminders')->updateOrInsert(
                ['dedupe_key' => $dedupeKey],
                [
                    'rule_id' => $ruleIds[$index % 2 === 0 ? 'dev_followup' : 'dev_appointment'],
                    'template_id' => $templateIds[$index % 3 === 0 ? 'dev_holiday' : 'dev_followup'],
                    'customer_id' => $customer['id'],
                    'order_id' => $orders[$index]['id'] ?? null,
                    'appointment_id' => null,
                    'assigned_to' => $customer['owner'],
                    'created_by' => $users['admin@example.test'],
                    'source_type' => 'custom',
                    'reminder_type' => $index % 3 === 0 ? 'holiday_date' : 'custom',
                    'title' => self::MARKER.($status === 'completed' ? '已完成提醒' : '待处理提醒').' '.$index,
                    'suggestion' => self::MARKER.'提醒建议',
                    'notes' => self::MARKER.'DevelopmentScenarioSeeder 提醒场景',
                    'priority' => ($index % 3) + 1,
                    'due_at' => $dueAt,
                    'recurrence' => json_encode($index % 5 === 0 ? ['frequency' => 'monthly'] : [], JSON_THROW_ON_ERROR),
                    'status' => $status,
                    'notification_status' => $status === 'completed' ? 'sent' : ($index <= 20 ? 'queued' : 'pending'),
                    'notified_at' => $status === 'completed' ? now()->subDays(2) : null,
                    'completed_at' => $status === 'completed' ? now()->subDays(1) : null,
                    'completed_by' => $status === 'completed' ? ($customer['owner'] ?? $users['admin@example.test']) : null,
                    'localized_content' => json_encode(['title' => ['key' => 'development.reminder.title', 'parameters' => ['index' => $index]]], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $reminderId = DB::table('reminders')->where('dedupe_key', $dedupeKey)->value('id');
            if ($index <= 20) {
                DB::table('reminder_events')->updateOrInsert(
                    ['reminder_id' => $reminderId, 'event' => $status === 'completed' ? 'completed' : 'snoozed', 'occurred_at' => $dueAt],
                    ['actor_id' => $customer['owner'] ?? $users['admin@example.test'], 'properties' => json_encode(['source' => 'development_scenario'], JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        foreach (['settlement_completed', 'reminder_due', 'customer_transfer'] as $eventIndex => $eventType) {
            foreach (array_slice(array_values($users), 0, 3) as $userId) {
                DB::table('notification_recipient_configs')->updateOrInsert(
                    ['event_type' => $eventType, 'user_id' => $userId, 'channel' => 'internal'],
                    ['enabled' => $eventIndex !== 2, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
        for ($index = 1; $index <= 20; $index++) {
            $userId = array_values($users)[$index % count($users)];
            DB::table('internal_notifications')->updateOrInsert(
                ['user_id' => $userId, 'event_key' => self::MARKER.'development-notification-'.$index],
                ['event_type' => 'development_scenario', 'title' => self::MARKER.'开发场景通知 '.$index, 'body' => self::MARKER.'用于通知中心列表和已读状态测试。', 'link' => '/reminders', 'read_at' => $index % 3 === 0 ? now()->subDay() : null, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedImports(int $adminId): void
    {
        $statuses = ['completed', 'completed', 'validated', 'needs_review', 'failed', 'completed', 'validated', 'needs_review', 'completed', 'failed'];
        for ($index = 1; $index <= 10; $index++) {
            $batchId = $this->uuid(100 + $index);
            $status = $statuses[$index - 1];
            DB::table('import_batches')->updateOrInsert(
                ['id' => $batchId],
                [
                    'created_by' => $adminId,
                    'committed_by' => in_array($status, ['completed'], true) ? $adminId : null,
                    'kind' => $index % 2 === 0 ? 'historical' : 'daily',
                    'operation_mode' => $index === 5 ? 'historical_correction' : 'normal',
                    'operation_reason' => $index === 5 ? self::MARKER.'历史更正演示批次' : null,
                    'status' => $status,
                    'total_rows' => 3,
                    'valid_rows' => $status === 'failed' ? 1 : 3,
                    'warning_rows' => $status === 'needs_review' ? 2 : 0,
                    'error_rows' => $status === 'failed' ? 2 : 0,
                    'completed_at' => in_array($status, ['completed', 'failed'], true) ? now()->subDays($index) : null,
                    'rollback_expires_at' => $status === 'completed' ? now()->addDays(3) : null,
                    'summary' => json_encode(['source' => 'development_scenario', 'marker' => self::MARKER, 'batch_number' => $index], JSON_THROW_ON_ERROR),
                    'failure_reason' => $status === 'failed' ? self::MARKER.'模拟导入失败，待人工处理。' : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $sha = hash('sha256', self::MARKER.'import-file-'.$index);
            DB::table('import_files')->updateOrInsert(
                ['import_batch_id' => $batchId, 'sha256' => $sha],
                ['original_name' => self::MARKER.'导入批次-'.$index.'.xlsx', 'extension' => 'xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size_bytes' => 4096 + $index, 'encrypted_path' => 'development/scenario/'.$batchId.'.enc', 'profile' => $index % 2 === 0 ? 'customer' : 'order', 'status' => $status === 'failed' ? 'failed' : 'processed', 'updated_at' => now(), 'created_at' => now()],
            );
            $fileId = DB::table('import_files')->where(['import_batch_id' => $batchId, 'sha256' => $sha])->value('id');
            for ($row = 1; $row <= 3; $row++) {
                DB::table('import_rows')->updateOrInsert(
                    ['import_batch_id' => $batchId, 'import_file_id' => $fileId, 'source_row' => $row],
                    ['sheet_name' => 'Sheet1', 'profile' => $index % 2 === 0 ? 'customer' : 'order', 'status' => $status === 'failed' && $row > 1 ? 'failed' : ($status === 'needs_review' && $row === 3 ? 'needs_review' : 'validated'), 'raw_payload_encrypted' => self::MARKER.'encrypted-row-'.$index.'-'.$row, 'normalized_data' => json_encode(['marker' => self::MARKER, 'row' => $row], JSON_THROW_ON_ERROR), 'errors' => $status === 'failed' && $row > 1 ? json_encode(['code' => 'demo_failure'], JSON_THROW_ON_ERROR) : null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            if (in_array($status, ['needs_review', 'failed'], true)) {
                DB::table('import_issues')->updateOrInsert(
                    ['import_batch_id' => $batchId, 'code' => 'development_'.$status],
                    ['import_file_id' => $fileId, 'stage' => 'validation', 'severity' => $status === 'failed' ? 'error' : 'warning', 'profile' => 'customer', 'sheet_name' => 'Sheet1', 'source_row' => 2, 'field' => 'name', 'message' => self::MARKER.'导入问题演示记录', 'is_ignorable' => $status === 'needs_review', 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    /** @param array<int, array{id: int, code: string, group: string, bd: int}> $agents */
    private function seedSettlements(array $agents, int $adminId): void
    {
        $commissionRows = DB::table('order_commissions as commission')->join('orders', 'orders.id', '=', 'commission.order_id')->whereNotNull('orders.occurred_on')->get(['commission.id', 'commission.agent_id', 'commission.amount_krw as commission_krw', 'commission.rule_snapshot', 'orders.amount_krw as consumption_krw', 'orders.occurred_on']);
        for ($monthsAgo = 1; $monthsAgo <= 5; $monthsAgo++) {
            $start = CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo);
            $end = $start->endOfMonth();
            $runId = $this->uuid(200 + $monthsAgo);
            $isLatest = $monthsAgo === 1;
            $runId = $this->seedSettlementRunHeader($runId, $start, $end, $adminId);
            $periodRows = $commissionRows->filter(fn (object $row): bool => (string) $row->occurred_on >= $start->toDateString() && (string) $row->occurred_on <= $end->toDateString());
            $settlementIds = [];
            foreach ($agents as $agentIndex => $agent) {
                if ($isLatest && $agentIndex === count($agents) - 1) {
                    continue;
                }
                $rows = $periodRows->where('agent_id', $agent['id']);
                if ($rows->isEmpty()) {
                    continue;
                }
                $consumption = (int) $rows->sum('consumption_krw');
                $commission = (int) $rows->sum('commission_krw');
                $rate = $monthsAgo === 1 ? '218' : (string) (214 + $monthsAgo);
                $status = $monthsAgo >= 2 ? 'paid' : ($agentIndex % 4 === 0 ? 'pending_review' : 'approved');
                $settlementId = app(SettlementImportGateway::class)->upsertSettlement(new SettlementImportData(
                    agentId: $agent['id'],
                    periodStart: $start,
                    periodEnd: $end,
                    settledOn: $monthsAgo >= 2 ? $end->addDays(5) : null,
                    exchangeRateKrwPerCny: $rate,
                    totalConsumptionKrw: $consumption,
                    totalCommissionKrw: $commission,
                    payoutAmountCnyFen: intdiv($commission * 100, (int) $rate),
                    status: $status,
                    importBatchId: null,
                    agentSnapshot: ['id' => $agent['id'], 'code' => $agent['code'], 'name' => self::MARKER.'代理商'],
                ));
                $settlementIds[$agent['id']] = $settlementId;
                DB::table('settlements')->where('id', $settlementId)->update([
                    'settlement_run_id' => $runId,
                    'settlement_currency' => 'CNY',
                    'exchange_rate' => $rate,
                    'exchange_rate_date' => $end->toDateString(),
                    'exchange_rate_source' => self::MARKER.'固定模拟汇率',
                    'generation_status' => $monthsAgo >= 2 ? 'not_applicable' : 'generated',
                    'generated_at' => $monthsAgo === 1 ? now()->subDays(2) : null,
                    'item_count' => $rows->count(),
                    'reviewed_by' => $monthsAgo === 1 ? $adminId : null,
                    'reviewed_at' => $monthsAgo === 1 ? now()->subDay() : null,
                    'settled_by' => $monthsAgo >= 2 ? $adminId : null,
                    'confirmed_at' => $monthsAgo >= 2 ? $end->addDays(6) : null,
                    'exchange_rate_quote_status' => $monthsAgo === 1 ? 'available' : 'not_requested',
                    'exchange_rate_manual_override' => false,
                    'snapshot' => json_encode(['source' => 'development_scenario', 'marker' => self::MARKER, 'exchange_rate_krw_per_cny' => $rate], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                foreach ($rows as $row) {
                    DB::table('settlement_items')->updateOrInsert(
                        ['settlement_id' => $settlementId, 'order_commission_id' => $row->id],
                        ['consumption_krw' => $row->consumption_krw, 'commission_krw' => $row->commission_krw, 'rule_snapshot' => is_string($row->rule_snapshot) ? $row->rule_snapshot : json_encode($row->rule_snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
            $this->seedSettlementRun($runId, $start, $end, $settlementIds, $agents, $adminId, $isLatest);
        }
        $this->seedSettlementArtifacts($adminId);
    }

    /**
     * @param  array<int, int>  $settlementIds
     * @param  array<int, array{id: int, code: string, group: string, bd: int}>  $agents
     */
    private function seedSettlementRun(string $runId, CarbonImmutable $start, CarbonImmutable $end, array $settlementIds, array $agents, int $adminId, bool $isLatest): void
    {
        $failedAgentId = $isLatest ? $agents[count($agents) - 1]['id'] : null;
        $failed = $failedAgentId === null ? 0 : 1;
        $processed = count($settlementIds);
        $summary = DB::table('settlements')
            ->whereIn('id', array_values($settlementIds))
            ->selectRaw('COALESCE(SUM(total_consumption_krw), 0) as total_consumption')
            ->selectRaw('COALESCE(SUM(total_commission_krw), 0) as total_commission')
            ->first();
        DB::table('settlement_runs')->updateOrInsert(
            ['id' => $runId],
            [
                'configuration_id' => DB::table('settlement_configurations')->where('effective_from', now()->startOfMonth()->toDateString())->value('id'),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'trigger_source' => 'manual',
                'status' => $failed > 0 ? 'partial_failed' : 'completed',
                'total_agents' => count($agents),
                'processed_agents' => $processed,
                'existing_agents' => $isLatest ? 0 : $processed,
                'existing_agent_ids' => json_encode($isLatest ? [] : array_keys($settlementIds), JSON_THROW_ON_ERROR),
                'failed_agents' => $failed,
                'total_consumption_krw' => (int) $summary->total_consumption,
                'total_commission_krw' => (int) $summary->total_commission,
                'initiated_by' => $adminId,
                'started_at' => $end->addDays(2),
                'completed_at' => $end->addDays(3),
                'notification_status' => 'sent',
                'notified_at' => $end->addDays(3),
                'errors' => $failed > 0 ? json_encode([$failedAgentId => ['message_key' => 'settlements.failure_reasons.demo_failure', 'parameters' => []]], JSON_THROW_ON_ERROR) : null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        foreach ($agents as $agent) {
            $outcome = $agent['id'] === $failedAgentId ? 'failed' : (isset($settlementIds[$agent['id']]) ? ($isLatest ? 'generated' : 'existing') : 'failed');
            DB::table('settlement_run_members')->updateOrInsert(
                ['settlement_run_id' => $runId, 'agent_id' => $agent['id']],
                ['settlement_id' => $outcome === 'failed' ? null : $settlementIds[$agent['id']], 'outcome' => $outcome, 'attempt_count' => 1, 'error_message_key' => $outcome === 'failed' ? 'settlements.failure_reasons.demo_failure' : null, 'error_parameters' => $outcome === 'failed' ? json_encode([], JSON_THROW_ON_ERROR) : null, 'processed_at' => $end->addDays(3), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedSettlementArtifacts(int $adminId): void
    {
        $settlements = DB::table('settlements')
            ->whereRaw("snapshot->>'source' = 'development_scenario'")
            ->where('status', 'paid')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'agent_id', 'total_commission_krw']);
        foreach ($settlements as $settlement) {
            foreach (['pdf', 'docx'] as $format) {
                DB::table('settlement_documents')->updateOrInsert(
                    ['settlement_id' => $settlement->id, 'format' => $format],
                    [
                        'path' => 'development/scenario/settlements/'.$settlement->id.'.'.$format,
                        'sha256' => hash('sha256', self::MARKER.'settlement-'.$settlement->id.'-'.$format),
                        'content_snapshot' => json_encode(['source' => 'development_scenario', 'marker' => self::MARKER, 'settlement_id' => $settlement->id], JSON_THROW_ON_ERROR),
                        'generated_at' => now()->subDays(2),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        $grades = DB::table('policy_grades')->orderBy('id')->pluck('id')->values();
        $pendingSettlements = DB::table('settlements')
            ->whereRaw("snapshot->>'source' = 'development_scenario'")
            ->where('status', 'pending_review')
            ->get(['id', 'agent_id', 'period_start', 'total_commission_krw']);
        foreach ($pendingSettlements as $settlement) {
            $currentGrade = DB::table('agent_grade_assignments')
                ->where('agent_id', $settlement->agent_id)
                ->orderByDesc('effective_month')
                ->value('policy_grade_id');
            if ($currentGrade === null || $grades->isEmpty()) {
                continue;
            }
            $gradeIndex = $grades->search((int) $currentGrade);
            $recommendedGrade = $grades[($gradeIndex === false ? 0 : $gradeIndex + 1) % $grades->count()];
            DB::table('settlement_grade_suggestions')->updateOrInsert(
                ['settlement_id' => $settlement->id],
                [
                    'agent_id' => $settlement->agent_id,
                    'current_grade_id' => $currentGrade,
                    'recommended_grade_id' => $recommendedGrade,
                    'monthly_commission_krw' => $settlement->total_commission_krw,
                    'status' => 'pending',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            DB::table('agent_grade_evaluations')->updateOrInsert(
                ['agent_id' => $settlement->agent_id, 'period' => $settlement->period_start],
                [
                    'settlement_id' => $settlement->id,
                    'current_grade_id' => $currentGrade,
                    'evaluated_grade_id' => $recommendedGrade,
                    'result' => 'upgrade_suggestion',
                    'consecutive_failure_count' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    private function seedSettlementRunHeader(string $runId, CarbonImmutable $start, CarbonImmutable $end, int $adminId): string
    {
        $existingRunId = DB::table('settlement_runs')
            ->where('period_start', $start->toDateString())
            ->where('period_end', $end->toDateString())
            ->value('id');
        if ($existingRunId !== null) {
            return (string) $existingRunId;
        }

        DB::table('settlement_runs')->updateOrInsert(
            ['id' => $runId],
            [
                'configuration_id' => DB::table('settlement_configurations')->where('effective_from', CarbonImmutable::now()->startOfMonth()->toDateString())->value('id'),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'trigger_source' => 'manual',
                'initiated_by' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $runId;
    }

    /**
     * @param  array<string, int>  $groups
     * @param  array<string, int>  $users
     */
    private function seedBdQuarterlyCommissions(array $groups, array $users): void
    {
        DB::table('bd_commission_rules')->updateOrInsert(['effective_from' => now()->startOfQuarter()->toDateString()], ['base_type' => 'order_amount_krw', 'currency' => 'KRW', 'rate_bps' => 100, 'created_by' => $users['admin@example.test'], 'reason' => self::MARKER.'季度 BD 规则', 'metadata' => json_encode(['source' => 'development_scenario', 'marker' => self::MARKER], JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
        for ($quartersAgo = 1; $quartersAgo <= 2; $quartersAgo++) {
            $start = CarbonImmutable::now()->startOfQuarter()->subQuarters($quartersAgo);
            $end = $start->endOfQuarter();
            $rows = DB::table('orders')->where('record_status', 'active')->where('status', 'completed')->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->get(['id', 'amount_krw', 'occurred_on', 'business_attribution_snapshot']);
            $totalBasis = (int) $rows->sum('amount_krw');
            $totalCommission = intdiv($totalBasis * 100, 10000);
            DB::table('bd_quarterly_commissions')->updateOrInsert(
                ['quarter_start' => $start->toDateString(), 'quarter_end' => $end->toDateString()],
                ['status' => $quartersAgo === 1 ? 'reviewed' : 'confirmed', 'currency' => 'KRW', 'total_basis_krw' => $totalBasis, 'total_adjustment_krw' => 0, 'total_commission_krw' => $totalCommission, 'rule_snapshot' => json_encode(['source' => 'development_scenario', 'marker' => self::MARKER, 'rate_bps' => 100], JSON_THROW_ON_ERROR), 'generated_by' => $users['admin@example.test'], 'generated_at' => now()->subDays(4), 'reviewed_by' => $users['admin@example.test'], 'reviewed_at' => now()->subDays(3), 'confirmed_by' => $quartersAgo === 2 ? $users['admin@example.test'] : null, 'confirmed_at' => $quartersAgo === 2 ? now()->subDays(2) : null, 'updated_at' => now(), 'created_at' => now()],
            );
            $quarterId = DB::table('bd_quarterly_commissions')->where(['quarter_start' => $start->toDateString(), 'quarter_end' => $end->toDateString()])->value('id');
            foreach ($rows as $row) {
                $snapshot = is_string($row->business_attribution_snapshot) ? json_decode($row->business_attribution_snapshot, true, 512, JSON_THROW_ON_ERROR) : (array) $row->business_attribution_snapshot;
                $groupCode = $snapshot['business_group']['code'] ?? 'BD-A';
                $bdId = $groupCode === 'BD-A' ? $users['bd.a@example.test'] : $users['bd.b@example.test'];
                DB::table('bd_quarterly_commission_items')->updateOrInsert(
                    ['quarterly_commission_id' => $quarterId, 'order_id' => $row->id],
                    ['bd_user_id' => $bdId, 'business_group_id' => $groups[$groupCode], 'occurred_on' => $row->occurred_on, 'basis_krw' => $row->amount_krw, 'rate_bps' => 100, 'commission_krw' => intdiv($row->amount_krw * 100, 10000), 'currency' => 'KRW', 'attribution_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'rule_snapshot' => json_encode(['rate_bps' => 100, 'marker' => self::MARKER], JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    /** @param array<string, int> $users */
    private function seedReports(array $users): void
    {
        $queries = [
            ['客户生命周期总览', ['status' => 'all'], 'name'],
            ['本月完成订单', ['completed_from' => now()->startOfMonth()->toDateString(), 'completed_to' => now()->toDateString()], 'completed_at'],
            ['BD-A 高金额订单', ['business_group' => 'BD-A', 'amount_min' => 5000000], 'amount_krw'],
        ];
        foreach ($queries as $index => [$name, $criteria, $sort]) {
            DB::table('report_saved_queries')->updateOrInsert(
                ['created_by' => $users['admin@example.test'], 'name' => self::MARKER.$name],
                ['scope' => $index === 0 ? 'shared' : 'personal', 'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR), 'sort_field' => $sort, 'sort_direction' => 'desc', 'updated_at' => now(), 'created_at' => now()],
            );
        }
        foreach ([['301', 'xlsx', 'completed'], ['302', 'pdf', 'failed']] as [$sequence, $format, $status]) {
            $exportId = $this->uuid((int) $sequence);
            DB::table('report_exports')->updateOrInsert(
                ['id' => $exportId],
                [
                    'created_by' => $users['admin@example.test'],
                    'kind' => 'orders',
                    'format' => $format,
                    'status' => $status,
                    'criteria_snapshot' => json_encode(['marker' => self::MARKER, 'scenario' => 'development'], JSON_THROW_ON_ERROR),
                    'data_snapshot' => $status === 'completed' ? json_encode(['rows' => 12, 'marker' => self::MARKER], JSON_THROW_ON_ERROR) : null,
                    'path' => $status === 'completed' ? 'development/scenario/reports/'.$exportId.'.'.$format : null,
                    'sha256' => $status === 'completed' ? hash('sha256', self::MARKER.'report-'.$sequence) : null,
                    'failure_reason' => $status === 'failed' ? self::MARKER.'模拟导出失败记录' : null,
                    'generated_at' => $status === 'completed' ? now()->subHour() : null,
                    'expires_at' => now()->addDay(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    private function seedAuditLog(int $adminId): void
    {
        DB::table('activity_log')->where('batch_uuid', self::AUDIT_BATCH)->delete();
        for ($index = 1; $index <= 120; $index++) {
            DB::table('activity_log')->insert([
                'log_name' => $index % 4 === 0 ? 'order' : ($index % 3 === 0 ? 'customer' : 'development-scenario'),
                'description' => self::MARKER.'开发场景审计记录 '.$index,
                'event' => ['created', 'updated', 'status_changed', 'viewed'][$index % 4],
                'subject_type' => null,
                'subject_id' => null,
                'causer_type' => User::class,
                'causer_id' => $adminId,
                'properties' => json_encode(['marker' => self::MARKER, 'scenario' => 'development', 'sequence' => $index], JSON_THROW_ON_ERROR),
                'batch_uuid' => self::AUDIT_BATCH,
                'created_at' => now()->subDays(120 - $index),
                'updated_at' => now()->subDays(120 - $index),
            ]);
        }
    }

    private function uuid(int $sequence): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $sequence);
    }

    /**
     * @param  array<string, scalar>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertId(string $table, array $keys, array $values): int
    {
        $query = DB::table($table);
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }
        $id = $query->value('id');
        if ($id === null) {
            return (int) DB::table($table)->insertGetId([...$keys, ...$values]);
        }

        DB::table($table)->where($keys)->update($values);

        return (int) $id;
    }
}
