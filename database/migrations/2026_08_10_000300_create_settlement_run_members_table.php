<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_run_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid('settlement_run_id');
            $table->foreign('settlement_run_id')->references('id')->on('settlement_runs')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('settlements')->restrictOnDelete();
            $table->string('outcome', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('error_message_key', 160)->nullable();
            $table->jsonb('error_parameters')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['settlement_run_id', 'agent_id']);
            $table->index(['settlement_run_id', 'outcome']);
            $table->index('settlement_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE settlement_run_members ADD CONSTRAINT settlement_run_members_outcome_check CHECK (outcome IN ('pending', 'generated', 'existing', 'failed'))");
            DB::statement("ALTER TABLE settlement_run_members ADD CONSTRAINT settlement_run_members_result_check CHECK ((outcome IN ('generated', 'existing') AND settlement_id IS NOT NULL) OR (outcome IN ('pending', 'failed') AND settlement_id IS NULL))");
            DB::statement("ALTER TABLE settlement_run_members ADD CONSTRAINT settlement_run_members_failure_check CHECK (outcome <> 'failed' OR error_message_key IS NOT NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_run_members');
    }
};
