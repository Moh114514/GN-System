<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->timestamp('arrived_at')->nullable()->after('status');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('registration_key')->nullable()->unique();
            $table->char('registration_fingerprint', 64)->nullable();
            $table->boolean('settlement_excluded')->default(false);
        });

        Schema::table('settlements', function (Blueprint $table): void {
            $table->date('payment_due_on')->nullable();
        });
        Schema::create('settlement_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_commission_id')->nullable()->constrained('order_commissions')->restrictOnDelete();
            $table->foreignId('original_settlement_id')->nullable()->constrained('settlements')->restrictOnDelete();
            $table->foreignId('target_settlement_id')->nullable()->constrained('settlements')->restrictOnDelete();
            $table->string('adjustment_key', 128)->unique();
            $table->date('eligible_period_start');
            $table->bigInteger('consumption_delta_krw')->default(0);
            $table->bigInteger('commission_delta_krw')->default(0);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('snapshot')->default('{}');
            $table->timestamps();
            $table->index(['agent_id', 'target_settlement_id', 'eligible_period_start'], 'settlement_adjustments_pending_idx');
        });

        Schema::create('order_registration_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('completed_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('payload');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        $hasBusinessData = DB::table('appointments')->whereNotNull('arrived_at')->exists()
            || DB::table('orders')->whereNotNull('registration_key')->orWhere('settlement_excluded', true)->exists()
            || DB::table('settlements')->whereNotNull('payment_due_on')->exists()
            || DB::table('settlement_adjustments')->exists()
            || DB::table('order_registration_drafts')->exists();
        if ($hasBusinessData) {
            throw new RuntimeException('订单登记、月结截止日、月结差额或到院事实已写入；请先备份并通过受控恢复方案处理，禁止直接回滚此迁移。');
        }

        Schema::dropIfExists('order_registration_drafts');
        Schema::dropIfExists('settlement_adjustments');

        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn('payment_due_on');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['registration_key']);
            $table->dropColumn(['registration_key', 'registration_fingerprint', 'settlement_excluded']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('arrived_at');
        });
    }
};
