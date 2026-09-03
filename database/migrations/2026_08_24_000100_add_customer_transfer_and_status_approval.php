<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('arrived_at')->nullable()->after('current_status_id');
        });

        Schema::create('customer_transfer_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('from_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('request_reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['to_owner_id', 'status']);
        });
        DB::statement("ALTER TABLE customer_transfer_requests ADD CONSTRAINT customer_transfer_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'expired'))");
        DB::statement("CREATE UNIQUE INDEX customer_transfer_requests_pending_unique ON customer_transfer_requests (customer_id) WHERE status = 'pending'");

        Schema::create('customer_owner_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('business_group_id')->nullable()->constrained('business_groups')->nullOnDelete();
            $table->foreignId('from_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24);
            $table->foreignId('transfer_request_id')->nullable()->constrained('customer_transfer_requests')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->index(['customer_id', 'effective_at']);
        });
        DB::statement("ALTER TABLE customer_owner_histories ADD CONSTRAINT customer_owner_histories_source_check CHECK (source IN ('initial', 'request', 'bd_direct', 'admin_cross_group', 'batch'))");

        Schema::create('customer_status_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('customer_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('customer_statuses')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('request_reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
        DB::statement("ALTER TABLE customer_status_change_requests ADD CONSTRAINT customer_status_change_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'expired'))");
        DB::statement("CREATE UNIQUE INDEX customer_status_change_requests_pending_unique ON customer_status_change_requests (customer_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_status_change_requests_pending_unique');
        DB::statement('ALTER TABLE customer_status_change_requests DROP CONSTRAINT IF EXISTS customer_status_change_requests_status_check');
        Schema::dropIfExists('customer_status_change_requests');

        DB::statement('ALTER TABLE customer_owner_histories DROP CONSTRAINT IF EXISTS customer_owner_histories_source_check');
        Schema::dropIfExists('customer_owner_histories');

        DB::statement('DROP INDEX IF EXISTS customer_transfer_requests_pending_unique');
        DB::statement('ALTER TABLE customer_transfer_requests DROP CONSTRAINT IF EXISTS customer_transfer_requests_status_check');
        Schema::dropIfExists('customer_transfer_requests');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('arrived_at');
        });
    }
};
