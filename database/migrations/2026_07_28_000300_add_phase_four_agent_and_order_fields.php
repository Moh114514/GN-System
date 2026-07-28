<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_type_codes', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->date('cooperation_ended_on')->nullable()->after('cooperation_started_on');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('status', 24)->default('pending')->after('owner_id');
        });

        DB::table('orders')->whereNotNull('completed_on')->update(['status' => 'completed']);
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'completed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('cooperation_ended_on');
        });

        Schema::table('agent_type_codes', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
