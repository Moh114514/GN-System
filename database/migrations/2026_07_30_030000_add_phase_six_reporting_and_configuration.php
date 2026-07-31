<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_dictionary_items', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'code']);
            $table->index(['type', 'is_active', 'name']);
        });

        Schema::create('system_parameters', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->string('value_type', 16);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('system_parameters')->insert([
            [
                'key' => 'report_default_per_page',
                'value' => json_encode(50, JSON_THROW_ON_ERROR),
                'value_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'dashboard_refresh_seconds',
                'value' => json_encode(300, JSON_THROW_ON_ERROR),
                'value_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_value')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('completed_at')->nullable();
            $table->string('completion_precision', 16)->default('date');
            $table->foreignId('treatment_project_id')->nullable()
                ->constrained('config_dictionary_items')->nullOnDelete();
            $table->string('treatment_project_snapshot')->nullable();
            $table->foreignId('translator_language_id')->nullable()
                ->constrained('config_dictionary_items')->nullOnDelete();
            $table->string('translator_language_snapshot')->nullable();
            $table->index('completed_at', 'orders_completed_at_index');
            $table->index(['agent_id', 'completed_at'], 'orders_agent_completed_at_index');
            $table->index(['institution_id', 'completed_at'], 'orders_institution_completed_at_index');
            $table->index('amount_krw', 'orders_amount_krw_index');
        });

        DB::table('orders')->whereNotNull('completed_on')->update([
            'completed_at' => DB::raw("completed_on::timestamp AT TIME ZONE 'Asia/Shanghai'"),
            'completion_precision' => 'date',
            'treatment_project_snapshot' => DB::raw('project_name'),
        ]);

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('treatment_project_id')->nullable()
                ->constrained('config_dictionary_items')->nullOnDelete();
            $table->string('treatment_project_snapshot')->nullable();
            $table->foreignId('translator_language_id')->nullable()
                ->constrained('config_dictionary_items')->nullOnDelete();
            $table->string('translator_language_snapshot')->nullable();
        });

        Schema::create('report_saved_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('scope', 16)->default('personal');
            $table->jsonb('criteria');
            $table->string('sort_field', 32)->default('completed_at');
            $table->string('sort_direction', 4)->default('desc');
            $table->timestamps();
            $table->index(['scope', 'created_by']);
        });

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('format', 8);
            $table->string('status', 16)->default('queued');
            $table->jsonb('criteria_snapshot');
            $table->jsonb('data_snapshot')->nullable();
            $table->string('path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['created_by', 'status']);
            $table->index('expires_at');
        });

        foreach (['agent', 'customer', 'settlement'] as $owner) {
            Schema::create("{$owner}_configuration_snapshots", function (Blueprint $table): void {
                $table->id();
                $table->string('configuration_type', 64);
                $table->string('action', 16);
                $table->jsonb('snapshot');
                $table->foreignId('target_snapshot_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['configuration_type', 'created_at']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('session_version')->default(1);
            $table->string('invitation_status', 24)->default('accepted');
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['is_active', 'is_super_admin']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['disabled_by']);
            $table->dropIndex(['is_active', 'is_super_admin']);
            $table->dropColumn([
                'is_active',
                'session_version',
                'invitation_status',
                'invitation_sent_at',
                'disabled_at',
                'disabled_by',
            ]);
        });

        foreach (['settlement', 'customer', 'agent'] as $owner) {
            Schema::dropIfExists("{$owner}_configuration_snapshots");
        }

        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_saved_queries');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['treatment_project_id']);
            $table->dropForeign(['translator_language_id']);
            $table->dropColumn([
                'treatment_project_id',
                'treatment_project_snapshot',
                'translator_language_id',
                'translator_language_snapshot',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_completed_at_index');
            $table->dropIndex('orders_agent_completed_at_index');
            $table->dropIndex('orders_institution_completed_at_index');
            $table->dropIndex('orders_amount_krw_index');
            $table->dropForeign(['treatment_project_id']);
            $table->dropForeign(['translator_language_id']);
            $table->dropColumn([
                'completed_at',
                'completion_precision',
                'treatment_project_id',
                'treatment_project_snapshot',
                'translator_language_id',
                'translator_language_snapshot',
            ]);
        });

        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn(['address', 'contact_name', 'contact_value']);
        });

        Schema::dropIfExists('system_parameters');
        Schema::dropIfExists('config_dictionary_items');
    }
};
