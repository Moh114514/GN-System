<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('base_type', 32);
            $table->char('currency', 3);
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique('effective_from', 'bd_commission_rules_effective_from_unique');
            $table->index(['base_type', 'currency', 'effective_from']);
        });

        Schema::create('bd_quarterly_commissions', function (Blueprint $table): void {
            $table->id();
            $table->date('quarter_start');
            $table->date('quarter_end');
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('KRW');
            $table->unsignedBigInteger('total_basis_krw')->default(0);
            $table->bigInteger('total_adjustment_krw')->default(0);
            $table->bigInteger('total_commission_krw')->default(0);
            $table->jsonb('rule_snapshot')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['quarter_start', 'quarter_end'], 'bd_quarterly_commissions_period_unique');
            $table->index(['status', 'quarter_start']);
        });

        Schema::create('bd_quarterly_commission_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quarterly_commission_id')->constrained('bd_quarterly_commissions')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('bd_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('business_group_id')->nullable();
            $table->date('occurred_on');
            $table->unsignedBigInteger('basis_krw');
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('commission_krw');
            $table->char('currency', 3);
            $table->jsonb('attribution_snapshot');
            $table->jsonb('rule_snapshot');
            $table->timestamps();
            $table->unique(['quarterly_commission_id', 'order_id'], 'bd_quarterly_commission_items_order_unique');
            $table->index(['bd_user_id', 'occurred_on']);
        });

        Schema::create('bd_commission_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quarterly_commission_id')->constrained('bd_quarterly_commissions')->cascadeOnDelete();
            $table->foreignId('bd_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('amount_krw');
            $table->char('currency', 3);
            $table->string('source', 32)->default('manual');
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('source_quarterly_commission_id')->nullable()->constrained('bd_quarterly_commissions')->nullOnDelete();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['quarterly_commission_id', 'bd_user_id']);
            $table->index(['source_order_id', 'source']);
        });

        DB::statement("ALTER TABLE bd_commission_rules ADD CONSTRAINT bd_commission_rules_base_type_check CHECK (base_type IN ('order_amount_krw'))");
        DB::statement('ALTER TABLE bd_commission_rules ADD CONSTRAINT bd_commission_rules_currency_check CHECK (currency = \'KRW\')');
        DB::statement('ALTER TABLE bd_commission_rules ADD CONSTRAINT bd_commission_rules_rate_check CHECK (rate_bps <= 10000)');
        DB::statement("ALTER TABLE bd_quarterly_commissions ADD CONSTRAINT bd_quarterly_commissions_status_check CHECK (status IN ('draft', 'generated', 'reviewed', 'confirmed'))");
        DB::statement('ALTER TABLE bd_quarterly_commissions ADD CONSTRAINT bd_quarterly_commissions_period_check CHECK (quarter_end >= quarter_start)');
        DB::statement('ALTER TABLE bd_quarterly_commission_items ADD CONSTRAINT bd_quarterly_commission_items_currency_check CHECK (currency = \'KRW\')');
        DB::statement('ALTER TABLE bd_commission_adjustments ADD CONSTRAINT bd_commission_adjustments_currency_check CHECK (currency = \'KRW\')');
    }

    public function down(): void
    {
        throw new RuntimeException('BD季度提成事实不可逆迁移，禁止自动回滚。');
    }
};
