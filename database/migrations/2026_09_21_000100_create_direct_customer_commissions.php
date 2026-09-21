<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['effective_from', 'effective_until'], 'direct_commission_rates_effective_index');
        });

        Schema::create('direct_order_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('order_amount_krw');
            $table->unsignedBigInteger('commission_amount_krw');
            $table->timestampTz('completed_at');
            $table->string('status', 16)->default('active');
            $table->jsonb('rule_snapshot');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'completed_at'], 'direct_order_commissions_owner_completed_index');
            $table->index(['order_id', 'status'], 'direct_order_commissions_order_status_index');
        });

        DB::statement('ALTER TABLE direct_commission_rates ADD CONSTRAINT direct_commission_rates_rate_check CHECK (rate_bps <= 10000)');
        DB::statement('ALTER TABLE direct_commission_rates ADD CONSTRAINT direct_commission_rates_period_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE direct_order_commissions ADD CONSTRAINT direct_order_commissions_status_check CHECK (status IN ('active', 'voided'))");
        DB::statement('ALTER TABLE direct_order_commissions ADD CONSTRAINT direct_order_commissions_rate_check CHECK (rate_bps <= 10000)');
    }

    public function down(): void
    {
        if (DB::table('direct_order_commissions')->exists() || DB::table('direct_commission_rates')->exists()) {
            throw new RuntimeException('Cannot roll back direct commission migration after commission facts or rates exist.');
        }

        Schema::dropIfExists('direct_order_commissions');
        Schema::dropIfExists('direct_commission_rates');
    }
};
