<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)->default('customer_service')->after('is_super_admin');
        });

        DB::table('users')->update([
            'role' => DB::raw("CASE WHEN is_super_admin THEN 'super_admin' ELSE 'customer_service' END"),
        ]);
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'bd_manager', 'customer_service'))");
        DB::statement('CREATE INDEX users_role_active_index ON users (role, is_active)');

        Schema::create('business_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::create('business_group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('member_role', 32);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['business_group_id', 'effective_from', 'effective_until']);
            $table->index(['user_id', 'effective_from', 'effective_until']);
        });

        Schema::create('agent_business_group_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('business_group_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['agent_id', 'effective_from', 'effective_until']);
            $table->index(['business_group_id', 'effective_from', 'effective_until']);
        });

        DB::statement("ALTER TABLE business_group_memberships ADD CONSTRAINT business_group_memberships_role_check CHECK (member_role IN ('bd_manager', 'customer_service'))");
        DB::statement('ALTER TABLE business_group_memberships ADD CONSTRAINT business_group_memberships_date_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement('ALTER TABLE agent_business_group_assignments ADD CONSTRAINT agent_business_group_assignments_date_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');

        // PostgreSQL range exclusion keeps historical intervals non-overlapping even
        // when two administrators submit mappings concurrently.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE business_group_memberships ADD CONSTRAINT business_group_memberships_bd_overlap_exclude EXCLUDE USING gist (business_group_id WITH =, daterange(effective_from, COALESCE(effective_until + 1, 'infinity'::date), '[)') WITH &&) WHERE (member_role = 'bd_manager')");
        DB::statement("ALTER TABLE business_group_memberships ADD CONSTRAINT business_group_memberships_user_overlap_exclude EXCLUDE USING gist (user_id WITH =, daterange(effective_from, COALESCE(effective_until + 1, 'infinity'::date), '[)') WITH &&)");
        DB::statement("ALTER TABLE agent_business_group_assignments ADD CONSTRAINT agent_business_group_assignments_overlap_exclude EXCLUDE USING gist (agent_id WITH =, daterange(effective_from, COALESCE(effective_until + 1, 'infinity'::date), '[)') WITH &&)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agent_business_group_assignments DROP CONSTRAINT IF EXISTS agent_business_group_assignments_overlap_exclude');
        DB::statement('ALTER TABLE business_group_memberships DROP CONSTRAINT IF EXISTS business_group_memberships_user_overlap_exclude');
        DB::statement('ALTER TABLE business_group_memberships DROP CONSTRAINT IF EXISTS business_group_memberships_bd_overlap_exclude');
        DB::statement('ALTER TABLE agent_business_group_assignments DROP CONSTRAINT IF EXISTS agent_business_group_assignments_date_check');
        DB::statement('ALTER TABLE business_group_memberships DROP CONSTRAINT IF EXISTS business_group_memberships_date_check');
        DB::statement('ALTER TABLE business_group_memberships DROP CONSTRAINT IF EXISTS business_group_memberships_role_check');

        Schema::dropIfExists('agent_business_group_assignments');
        Schema::dropIfExists('business_group_memberships');
        Schema::dropIfExists('business_groups');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_role_active_index');
            $table->dropColumn('role');
        });
    }
};
