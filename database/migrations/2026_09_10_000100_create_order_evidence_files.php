<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_evidence_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('encrypted_path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestamps();
            $table->index(['order_id', 'type']);
            $table->unique(['order_id', 'type', 'sha256'], 'order_evidence_order_type_sha256_unique');
        });
        DB::statement("ALTER TABLE order_evidence_files ADD CONSTRAINT order_evidence_type_check CHECK (type IN ('communication_screenshot', 'settlement_receipt'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_evidence_files DROP CONSTRAINT IF EXISTS order_evidence_type_check');
        Schema::dropIfExists('order_evidence_files');
    }
};
