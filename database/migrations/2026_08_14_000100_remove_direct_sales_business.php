<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $customerDirectCount = DB::table('customers')
            ->where(function ($query): void {
                $query->where('original_channel', 'direct')
                    ->orWhereNotNull('source_direct_sales_id');
            })
            ->count();
        $orderDirectCount = DB::table('orders')
            ->where(function ($query): void {
                $query->where('channel', 'direct')
                    ->orWhereNotNull('direct_sales_source_id');
            })
            ->count();
        $customerWithoutAgent = DB::table('customers')->whereNull('source_agent_id')->count();
        $orderWithoutAgent = DB::table('orders')->whereNull('agent_id')->count();

        if ($customerDirectCount > 0 || $orderDirectCount > 0) {
            throw new RuntimeException(sprintf(
                'Cannot remove direct-sales schema: found %d direct customer row(s) and %d direct order row(s). Resolve these records explicitly before retrying.',
                $customerDirectCount,
                $orderDirectCount,
            ));
        }
        if ($customerWithoutAgent > 0 || $orderWithoutAgent > 0) {
            throw new RuntimeException(sprintf(
                'Cannot enforce agent ownership: found %d customer row(s) and %d order row(s) without an agent.',
                $customerWithoutAgent,
                $orderWithoutAgent,
            ));
        }

        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_channel_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_channel_check');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_source_direct_sales_id_foreign');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_direct_sales_source_id_foreign');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['original_channel', 'source_direct_sales_id']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'direct_sales_source_id']);
        });

        DB::statement('ALTER TABLE customers ALTER COLUMN source_agent_id SET NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN agent_id SET NOT NULL');
        Schema::dropIfExists('direct_sales_sources');
    }

    public function down(): void
    {
        throw new RuntimeException('The direct-sales removal migration is irreversible because removed data cannot be reconstructed safely.');
    }
};
