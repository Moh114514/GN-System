<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->string('settlement_currency', 3)->default('CNY')->after('agent_id');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('settlement_currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate_krw_per_cny');
            $table->string('exchange_rate_source')->nullable()->after('exchange_rate_date');
        });

        DB::table('settlements')
            ->where('payout_amount_cny_fen', '>', 0)
            ->update(['settlement_currency' => 'CNY']);
        DB::statement('UPDATE settlements SET exchange_rate = exchange_rate_krw_per_cny WHERE exchange_rate_krw_per_cny IS NOT NULL');

        Schema::create('agent_grade_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->date('period');
            $table->foreignId('current_grade_id')->constrained('policy_grades')->restrictOnDelete();
            $table->foreignId('evaluated_grade_id')->constrained('policy_grades')->restrictOnDelete();
            $table->string('result', 32);
            $table->unsignedInteger('consecutive_failure_count')->default(0);
            $table->timestamps();
            $table->unique('settlement_id');
            $table->unique(['agent_id', 'period']);
            $table->index(['agent_id', 'period']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('dingtalk_user_id')->nullable()->after('email');
        });

        Schema::create('notification_recipient_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['event_type', 'user_id', 'channel']);
        });

        Schema::create('internal_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('event_key', 160);
            $table->string('title');
            $table->text('body');
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notifications');
        Schema::dropIfExists('notification_recipient_configs');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dingtalk_user_id');
        });
        Schema::dropIfExists('agent_grade_evaluations');
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn(['settlement_currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source']);
        });
    }
};
