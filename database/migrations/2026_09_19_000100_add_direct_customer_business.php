<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_customer_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('direct_customer_channels')->insertOrIgnore([
            ['code' => 'xiaohongshu', 'name' => '小红书', 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'douyin', 'name' => '抖音', 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'wechat', 'name' => '微信', 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'website', 'name' => '官网', 'is_active' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'phone', 'name' => '电话', 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'offline', 'name' => '线下', 'is_active' => true, 'sort_order' => 60, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'referral', 'name' => '老客户转介绍', 'is_active' => true, 'sort_order' => 70, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'other', 'name' => '其他', 'is_active' => true, 'sort_order' => 80, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('source_type', 16)->default('agent');
            $table->foreignId('direct_channel_id')->nullable()->constrained('direct_customer_channels')->restrictOnDelete();
            $table->index(['source_type', 'owner_id'], 'customers_source_type_owner_id_index');
            $table->index('direct_channel_id', 'customers_direct_channel_id_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('source_type', 16)->default('agent');
            $table->index('source_type', 'orders_source_type_index');
        });

        DB::statement('ALTER TABLE customers ALTER COLUMN source_agent_id DROP NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN agent_id DROP NOT NULL');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'bd_manager', 'customer_service', 'direct_customer_manager'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_source_type_check CHECK ((source_type = 'agent' AND source_agent_id IS NOT NULL AND direct_channel_id IS NULL) OR (source_type = 'direct' AND source_agent_id IS NULL AND direct_channel_id IS NOT NULL))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source_type_check CHECK ((source_type = 'agent' AND agent_id IS NOT NULL) OR (source_type = 'direct' AND agent_id IS NULL))");
    }

    public function down(): void
    {
        $directCustomers = DB::table('customers')->where('source_type', 'direct')->count();
        $directOrders = DB::table('orders')->where('source_type', 'direct')->count();
        if ($directCustomers > 0 || $directOrders > 0) {
            throw new RuntimeException(sprintf(
                'Cannot roll back direct customer business migration: found %d direct customer row(s) and %d direct order row(s).',
                $directCustomers,
                $directOrders,
            ));
        }

        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_source_type_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_source_type_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'bd_manager', 'customer_service'))");

        DB::statement('ALTER TABLE customers ALTER COLUMN source_agent_id SET NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN agent_id SET NOT NULL');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_source_type_index');
            $table->dropColumn('source_type');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['direct_channel_id']);
            $table->dropIndex('customers_source_type_owner_id_index');
            $table->dropIndex('customers_direct_channel_id_index');
            $table->dropColumn(['source_type', 'direct_channel_id']);
        });
        Schema::dropIfExists('direct_customer_channels');
    }
};
