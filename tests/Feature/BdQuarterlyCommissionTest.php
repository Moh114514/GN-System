<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Order\Application\Contracts\BdCommissionOrderReader;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Settlement\Application\Data\BdCommissionOrderData;
use App\Modules\Settlement\Application\Services\BdQuarterlyCommissionService;
use App\Modules\Settlement\Infrastructure\Models\BdCommissionRule;
use App\Modules\Settlement\Infrastructure\Models\BdQuarterlyCommission;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class BdQuarterlyCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $firstBd;

    private User $secondBd;

    private FakeBdCommissionOrderReader $reader;

    private Agent $agent;

    private Customer $customer;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-24 10:00:00');
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->firstBd = User::factory()->create(['role' => UserRole::BdManager]);
        $this->secondBd = User::factory()->create(['role' => UserRole::BdManager]);
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $type = AgentTypeCode::query()->firstOrFail();
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'BD-TEST',
            'name' => 'BD测试代理商',
            'cooperation_status' => 'active',
        ]);
        $this->institution = Institution::query()->firstOrFail();
        $this->customer = Customer::query()->create([
            'code' => 'BD-TEST-CUSTOMER',
            'name' => 'BD季度测试客户',
            'source_agent_id' => $this->agent->id,
            'owner_id' => $this->admin->id,
        ]);
        $this->reader = new FakeBdCommissionOrderReader;
        $this->app->instance(BdCommissionOrderReader::class, $this->reader);
        BdCommissionRule::query()->create([
            'base_type' => 'order_amount_krw',
            'currency' => 'KRW',
            'rate_bps' => 1000,
            'effective_from' => '2026-01-01',
            'created_by' => $this->admin->id,
            'reason' => '测试默认规则',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_quarter_boundaries_and_duplicate_orders_are_stable(): void
    {
        $this->reader->orders = [
            $this->order(1001, 10000, '2026-03-31', $this->firstBd->id),
            $this->order(1001, 10000, '2026-03-31', $this->firstBd->id),
            $this->order(1002, 20000, '2026-04-01', $this->firstBd->id),
        ];
        $service = $this->service();

        $preview = $service->preview(CarbonImmutable::parse('2026-03-01'));

        $this->assertSame('2026-01-01', $preview['period_start']);
        $this->assertSame('2026-03-31', $preview['period_end']);
        $this->assertSame(1, $preview['item_count']);
        $this->assertSame(1000, $preview['total_commission_krw']);
    }

    public function test_mid_quarter_agent_or_bd_transfer_uses_order_attribution_snapshot(): void
    {
        $this->reader->orders = [
            $this->order(2001, 10000, '2026-07-01', $this->firstBd->id, 11),
            $this->order(2002, 20000, '2026-08-01', $this->secondBd->id, 22),
        ];
        $period = $this->service()->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);

        $items = $period->items()->orderBy('order_id')->get();

        $this->assertSame([$this->firstBd->id, $this->secondBd->id], $items->pluck('bd_user_id')->all());
        $this->assertSame([11, 22], $items->pluck('business_group_id')->all());
        $this->assertSame(3000, (int) $period->total_commission_krw);
    }

    public function test_draft_can_be_regenerated_but_confirmed_period_is_immutable(): void
    {
        $this->reader->orders = [$this->order(3001, 10000, '2026-07-15', $this->firstBd->id)];
        $service = $this->service();
        $period = $service->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);
        $service->review((int) $period->id, $this->admin->id, null);
        $service->confirm((int) $period->id, $this->admin->id, null);
        $this->reader->orders = [$this->order(3001, 90000, '2026-07-15', $this->firstBd->id)];

        $confirmedPreview = $service->preview(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(1000, $confirmedPreview['total_commission_krw']);
        $this->expectExceptionMessage('已审核或已确认季度不可重算');
        $service->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);
    }

    public function test_manual_adjustment_requires_reason_and_is_audited(): void
    {
        $this->reader->orders = [$this->order(4001, 10000, '2026-07-15', $this->firstBd->id)];
        $period = $this->service()->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);
        $adjustment = $this->service()->addAdjustment((int) $period->id, $this->firstBd->id, -200, '人工复核差额', $this->admin->id, null);

        $this->assertSame(-200, (int) $adjustment->amount_krw);
        $this->assertSame(800, (int) $period->fresh()->total_commission_krw);
        $this->assertDatabaseHas('activity_log', ['subject_type' => $adjustment->getMorphClass(), 'subject_id' => $adjustment->id, 'event' => 'adjusted']);
    }

    public function test_bd_can_only_view_own_items_and_cannot_modify(): void
    {
        $this->reader->orders = [
            $this->order(5001, 10000, '2026-07-15', $this->firstBd->id),
            $this->order(5002, 20000, '2026-07-16', $this->secondBd->id),
        ];
        $period = $this->service()->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);

        $this->actingAs($this->firstBd);
        $detail = $this->service()->visibleDetail((int) $period->id);

        $this->assertCount(1, $detail['items']);
        $this->assertSame(5001, $detail['items'][0]['order_id']);
        $visiblePeriod = $this->service()->visiblePeriods()->firstOrFail();
        $this->assertSame(1000, (int) $visiblePeriod->total_commission_krw);
        $this->assertSame(1, (int) $visiblePeriod->items_count);
        try {
            $this->service()->addAdjustment((int) $period->id, $this->firstBd->id, 100, '越权调整', $this->firstBd->id, null);
            $this->fail('A BD user must not create a manual adjustment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_correction_difference_is_put_into_the_next_quarter(): void
    {
        $before = $this->order(6001, 10000, '2026-07-15', $this->firstBd->id);
        $this->reader->orders = [$before];
        $service = $this->service();
        $period = $service->generate(CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);
        $service->review((int) $period->id, $this->admin->id, null);
        $service->confirm((int) $period->id, $this->admin->id, null);

        $service->onOrderCorrected(
            $before,
            $this->order(6001, 20000, '2026-07-15', $this->firstBd->id),
            $this->admin->id,
            null,
        );

        $next = BdQuarterlyCommission::query()->whereDate('quarter_start', '2026-10-01')->firstOrFail();
        $this->assertSame(1000, (int) $next->total_adjustment_krw);
        $this->assertSame('order_correction', $next->adjustments()->value('source'));
    }

    public function test_bd_quarterly_page_is_available_to_bd_and_admin_with_dashboard_back_link(): void
    {
        $this->actingAs($this->admin)
            ->get(route('bd-commissions.index'))
            ->assertOk()
            ->assertSee('BD季度提成')
            ->assertSee(route('dashboard'), false)
            ->assertSee('wire:navigate', false);

        $this->actingAs($this->firstBd)
            ->get(route('bd-commissions.index'))
            ->assertOk()
            ->assertSee('BD季度提成');
    }

    private function service(): BdQuarterlyCommissionService
    {
        return app(BdQuarterlyCommissionService::class);
    }

    private function order(int $id, int $amount, string $date, int $bdUserId, int $groupId = 1): BdCommissionOrderData
    {
        Order::query()->firstOrCreate(['id' => $id], [
            'customer_id' => $this->customer->id,
            'institution_id' => $this->institution->id,
            'agent_id' => $this->agent->id,
            'project_name' => 'BD季度测试项目',
            'amount_krw' => $amount,
            'completed_on' => $date,
            'occurred_on' => $date,
            'completed_at' => $date.' 00:00:00',
            'completion_precision' => 'date',
            'status' => 'completed',
            'record_status' => 'active',
            'owner_id' => $this->admin->id,
            'business_attribution_snapshot' => [],
        ]);

        return new BdCommissionOrderData(
            orderId: $id,
            amountKrw: $amount,
            occurredOn: CarbonImmutable::parse($date),
            attributionSnapshot: [
                'business_group' => [
                    'business_group_id' => $groupId,
                    'bd_manager' => ['user_id' => $bdUserId, 'user_name' => '测试BD'],
                ],
                'occurred_on' => $date,
            ],
        );
    }
}

final class FakeBdCommissionOrderReader implements BdCommissionOrderReader
{
    /** @var list<BdCommissionOrderData> */
    public array $orders = [];

    public function completedBetween(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return array_values(array_filter(
            $this->orders,
            fn (BdCommissionOrderData $order): bool => $order->occurredOn->betweenIncluded($periodStart, $periodEnd),
        ));
    }
}
