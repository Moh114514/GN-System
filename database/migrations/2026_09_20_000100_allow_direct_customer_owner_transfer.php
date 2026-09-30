<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_owner_histories DROP CONSTRAINT IF EXISTS customer_owner_histories_source_check');
        DB::statement("ALTER TABLE customer_owner_histories ADD CONSTRAINT customer_owner_histories_source_check CHECK (source IN ('initial', 'request', 'bd_direct', 'admin_cross_group', 'admin_direct', 'batch'))");
    }

    public function down(): void
    {
        $count = DB::table('customer_owner_histories')->where('source', 'admin_direct')->count();
        if ($count > 0) {
            throw new RuntimeException(sprintf('Cannot roll back direct owner transfer migration: found %d admin_direct history row(s).', $count));
        }

        DB::statement('ALTER TABLE customer_owner_histories DROP CONSTRAINT IF EXISTS customer_owner_histories_source_check');
        DB::statement("ALTER TABLE customer_owner_histories ADD CONSTRAINT customer_owner_histories_source_check CHECK (source IN ('initial', 'request', 'bd_direct', 'admin_cross_group', 'batch'))");
    }
};
