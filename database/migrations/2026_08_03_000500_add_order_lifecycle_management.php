<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->softDeletesTz();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        DB::table('orders')->where('status', 'cancelled')->update(['status' => 'pending']);
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'completed'))");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropColumn([
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'deleted_at',
                'deleted_by',
                'deletion_reason',
            ]);
        });
    }
};
