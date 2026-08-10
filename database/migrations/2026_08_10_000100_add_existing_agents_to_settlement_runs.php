<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->unsignedInteger('existing_agents')->default(0)->after('processed_agents');
            $table->jsonb('existing_agent_ids')->nullable()->after('existing_agents');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->dropColumn('existing_agents');
            $table->dropColumn('existing_agent_ids');
        });
    }
};
