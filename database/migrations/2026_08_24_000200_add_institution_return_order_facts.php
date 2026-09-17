<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $blockers = [];

        $pending = DB::table('orders')->where('status', 'pending')->count();
        if ($pending > 0) {
            $blockers[] = "pending orders: {$pending}";
        }

        $missingDate = DB::table('orders')
            ->where('status', 'completed')
            ->whereNull('completed_on')
            ->count();
        if ($missingDate > 0) {
            $blockers[] = "completed orders without date: {$missingDate}";
        }

        $missingAgent = DB::table('orders')
            ->where('status', 'completed')
            ->whereNull('agent_id')
            ->count();
        if ($missingAgent > 0) {
            $blockers[] = "completed orders without agent: {$missingAgent}";
        }

        $missingCommission = DB::table('orders as orders')
            ->where('orders.status', 'completed')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('order_commissions')
                    ->whereColumn('order_commissions.order_id', 'orders.id');
            })
            ->count();
        if ($missingCommission > 0) {
            $blockers[] = "completed orders without commission snapshot: {$missingCommission}";
        }

        $invalidStatuses = DB::table('orders')
            ->whereNotIn('status', ['pending', 'completed', 'cancelled'])
            ->count();
        if ($invalidStatuses > 0) {
            $blockers[] = "orders with unmapped status: {$invalidStatuses}";
        }

        if ($blockers !== []) {
            throw new RuntimeException(
                'Cannot enable institution return order facts until existing data is repaired: '.implode('; ', $blockers),
            );
        }

        Schema::create('institution_form_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 64);
            $table->unsignedInteger('version');
            $table->jsonb('columns');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['institution_id', 'template_key', 'version'], 'institution_form_templates_version_unique');
            $table->index(['institution_id', 'template_key', 'is_active']);
        });

        Schema::create('institution_return_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('institution_form_templates')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('form_uuid')->nullable()->unique();
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->unique();
            $table->string('encrypted_path');
            $table->jsonb('metadata')->nullable();
            $table->char('integrity_signature', 64)->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->date('occurred_on')->nullable()->after('completed_on');
            $table->string('record_status', 16)->default('active')->after('status');
            $table->jsonb('business_attribution_snapshot')->nullable()->after('record_status');
            $table->uuid('source_return_file_id')->nullable()->unique()->after('import_batch_id');
            $table->foreign('source_return_file_id', 'orders_source_return_file_fk')
                ->references('id')
                ->on('institution_return_files')
                ->nullOnDelete();
            $table->index(['occurred_on', 'record_status'], 'orders_occurred_record_status_index');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_record_status_check CHECK (record_status IN ('active', 'voided'))");
        DB::table('orders')->whereNotNull('completed_on')->update(['occurred_on' => DB::raw('completed_on')]);

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_project_id')->nullable()->constrained('config_dictionary_items')->nullOnDelete();
            $table->string('project_snapshot');
            $table->string('specification')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_price_krw');
            $table->unsignedBigInteger('amount_krw');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('institution_return_files') && DB::table('institution_return_files')->exists()) {
            throw new RuntimeException('Institution return files contain original business evidence; rollback is refused.');
        }
        if (Schema::hasTable('order_items') && DB::table('order_items')->exists()) {
            throw new RuntimeException('Order items contain business facts; rollback is refused.');
        }
        if (Schema::hasColumn('orders', 'occurred_on') && DB::table('orders')->whereNotNull('occurred_on')->exists()) {
            throw new RuntimeException('Orders contain occurred_on facts; rollback is refused.');
        }

        Schema::dropIfExists('order_items');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_record_status_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign('orders_source_return_file_fk');
            $table->dropUnique(['source_return_file_id']);
            $table->dropIndex('orders_occurred_record_status_index');
            $table->dropColumn(['occurred_on', 'record_status', 'business_attribution_snapshot', 'source_return_file_id']);
        });
        Schema::dropIfExists('institution_return_files');
        Schema::dropIfExists('institution_form_templates');
    }
};
