<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_configurations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('boundary_day')->default(1);
            $table->time('trigger_time')->default('09:00:00');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->date('effective_from')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('settlement_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('configuration_id')->nullable()->constrained('settlement_configurations')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('trigger_source', 16);
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('total_agents')->default(0);
            $table->unsignedInteger('processed_agents')->default(0);
            $table->unsignedInteger('failed_agents')->default(0);
            $table->unsignedBigInteger('total_consumption_krw')->default(0);
            $table->unsignedBigInteger('total_commission_krw')->default(0);
            $table->string('progress_key')->nullable();
            $table->uuid('queue_batch_id')->nullable()->index();
            $table->jsonb('errors')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('notification_status', 24)->default('pending');
            $table->text('notification_error')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['period_start', 'period_end']);
        });

        Schema::table('settlements', function (Blueprint $table): void {
            $table->uuid('settlement_run_id')->nullable()->after('id');
            $table->foreign('settlement_run_id')->references('id')->on('settlement_runs')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->foreignId('settled_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('settled_by');
            $table->index(['settlement_run_id', 'status']);
        });

        Schema::create('settlement_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('format', 8);
            $table->string('path');
            $table->char('sha256', 64);
            $table->jsonb('content_snapshot');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['settlement_id', 'format']);
        });

        Schema::create('settlement_grade_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('current_grade_id')->constrained('policy_grades')->restrictOnDelete();
            $table->foreignId('recommended_grade_id')->constrained('policy_grades')->restrictOnDelete();
            $table->unsignedBigInteger('monthly_commission_krw');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique('settlement_id');
        });

        Schema::create('reminder_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('trigger_type', 32);
            $table->jsonb('trigger_config')->default('{}');
            $table->string('scope_type', 32)->default('all_customers');
            $table->jsonb('scope_config')->default('{}');
            $table->string('title');
            $table->text('suggestion')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reminder_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('suggestion')->nullable();
            $table->string('default_trigger_type', 32);
            $table->jsonb('default_trigger_config')->default('{}');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('reminder_rules')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('reminder_templates')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 32);
            $table->string('reminder_type', 32);
            $table->string('title');
            $table->text('suggestion')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->timestamp('due_at');
            $table->jsonb('recurrence')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('notification_status', 24)->default('pending');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->timestamps();
            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['notification_status', 'due_at']);
        });

        Schema::create('reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('properties')->default('{}');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['reminder_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_events');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('reminder_templates');
        Schema::dropIfExists('reminder_rules');
        Schema::dropIfExists('settlement_grade_suggestions');
        Schema::dropIfExists('settlement_documents');

        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropForeign(['settlement_run_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['settled_by']);
            $table->dropColumn([
                'settlement_run_id',
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
                'settled_by',
                'confirmed_at',
            ]);
        });

        Schema::dropIfExists('settlement_runs');
        Schema::dropIfExists('settlement_configurations');
    }
};
