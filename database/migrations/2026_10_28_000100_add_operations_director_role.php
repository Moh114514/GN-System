<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'bd_manager', 'customer_service', 'direct_customer_manager', 'operations_director'))");
    }

    public function down(): void
    {
        $operationsDirectors = DB::table('users')->where('role', 'operations_director')->count();
        if ($operationsDirectors > 0) {
            throw new RuntimeException(sprintf(
                'Cannot roll back operations director role migration: found %d operations director user(s).',
                $operationsDirectors,
            ));
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'bd_manager', 'customer_service', 'direct_customer_manager'))");
    }
};
