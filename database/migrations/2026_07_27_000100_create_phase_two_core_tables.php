<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('institution_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });

        Schema::create('agent_type_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 4)->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('policy_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('policy_grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_system_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('monthly_threshold_krw')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['policy_system_id', 'name']);
        });

        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_type_code_id')->constrained('agent_type_codes')->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('legacy_code', 32)->nullable()->unique();
            $table->string('name');
            $table->string('business_role')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('contact_value')->nullable();
            $table->date('cooperation_started_on')->nullable();
            $table->string('cooperation_status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('agent_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->string('status', 24)->default('pending');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('agent_grade_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_grade_id')->constrained()->restrictOnDelete();
            $table->date('effective_month');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['agent_id', 'effective_month']);
        });

        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rate_bps');
            $table->date('effective_month');
            $table->boolean('is_active')->default(true);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(
                ['policy_grade_id', 'institution_id', 'effective_month'],
                'commission_rules_grade_institution_month_unique',
            );
        });

        Schema::create('agent_commission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('direct_sales_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customer_lifecycle_stages', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stage_id')->constrained('customer_lifecycle_stages')->restrictOnDelete();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 32)->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('legacy_code', 48)->nullable()->unique();
            $table->string('name');
            $table->string('gender', 16)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('original_channel', 16);
            $table->foreignId('source_agent_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->foreignId('source_direct_sales_id')->nullable()->constrained('direct_sales_sources')->restrictOnDelete();
            $table->foreignId('current_status_id')->nullable()->constrained('customer_statuses')->nullOnDelete();
            $table->date('wechat_added_on')->nullable();
            $table->string('project_intention')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customer_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->text('value_encrypted');
            $table->char('lookup_hash', 64)->index();
            $table->boolean('is_primary')->default(false);
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customer_identity_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('number_encrypted');
            $table->char('lookup_hash', 64)->index();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customer_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('customer_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('customer_statuses')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->text('reason')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('translator_name')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('scheduled');
            $table->text('notes')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->foreignId('agent_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->foreignId('direct_sales_source_id')->nullable()->constrained('direct_sales_sources')->restrictOnDelete();
            $table->string('project_name');
            $table->unsignedBigInteger('amount_krw');
            $table->date('completed_on')->nullable();
            $table->string('translator_name')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('followup_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->date('followed_up_on')->nullable();
            $table->text('content')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('amount_krw');
            $table->jsonb('rule_snapshot');
            $table->text('override_reason')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique('order_id');
        });

        Schema::create('settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('settled_on')->nullable();
            $table->decimal('exchange_rate_krw_per_cny', 18, 6)->nullable();
            $table->unsignedBigInteger('total_consumption_krw')->default(0);
            $table->unsignedBigInteger('total_commission_krw')->default(0);
            $table->unsignedBigInteger('payout_amount_cny_fen')->default(0);
            $table->string('status', 24)->default('draft');
            $table->jsonb('snapshot')->nullable();
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['agent_id', 'period_start', 'period_end']);
        });

        Schema::create('settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_commission_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('consumption_krw');
            $table->unsignedBigInteger('commission_krw');
            $table->jsonb('rule_snapshot');
            $table->uuid('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['settlement_id', 'order_commission_id']);
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rollback_expires_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('summary')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('import_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_batch_id');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('extension', 8);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('encrypted_path');
            $table->string('profile', 32)->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->timestamps();
            $table->unique(['import_batch_id', 'sha256']);
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_batch_id');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->cascadeOnDelete();
            $table->foreignId('import_file_id')->constrained('import_files')->cascadeOnDelete();
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row');
            $table->string('profile', 32);
            $table->string('status', 24);
            $table->text('raw_payload_encrypted')->nullable();
            $table->jsonb('normalized_data')->nullable();
            $table->jsonb('errors')->nullable();
            $table->jsonb('resolution')->nullable();
            $table->timestamps();
            $table->index(['import_batch_id', 'status']);
        });

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('event')->nullable();
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('batch_uuid');
        });

        DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_cooperation_status_check CHECK (cooperation_status IN ('active', 'paused', 'terminated'))");
        DB::statement('ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_rate_check CHECK (rate_bps <= 10000)');
        DB::statement('ALTER TABLE agent_commission_overrides ADD CONSTRAINT agent_overrides_rate_check CHECK (rate_bps <= 10000)');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_channel_check CHECK ((original_channel = 'agent' AND source_agent_id IS NOT NULL AND source_direct_sales_id IS NULL) OR (original_channel = 'direct' AND source_agent_id IS NULL AND source_direct_sales_id IS NOT NULL))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_channel_check CHECK ((channel = 'agent' AND agent_id IS NOT NULL AND direct_sales_source_id IS NULL) OR (channel = 'direct' AND agent_id IS NULL AND direct_sales_source_id IS NOT NULL))");
        DB::statement('ALTER TABLE order_commissions ADD CONSTRAINT order_commissions_rate_check CHECK (rate_bps <= 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_files');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('order_commissions');
        Schema::dropIfExists('followup_records');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('customer_status_histories');
        Schema::dropIfExists('customer_identity_documents');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_number_sequences');
        Schema::dropIfExists('customer_statuses');
        Schema::dropIfExists('customer_lifecycle_stages');
        Schema::dropIfExists('direct_sales_sources');
        Schema::dropIfExists('agent_commission_overrides');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('agent_grade_assignments');
        Schema::dropIfExists('agent_contracts');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('policy_grades');
        Schema::dropIfExists('policy_systems');
        Schema::dropIfExists('agent_type_codes');
        Schema::dropIfExists('institution_aliases');
        Schema::dropIfExists('institutions');
    }
};
