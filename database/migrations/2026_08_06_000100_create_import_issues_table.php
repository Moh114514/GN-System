<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_batch_id');
            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();
            $table->foreignId('import_file_id')
                ->nullable()
                ->constrained('import_files')
                ->nullOnDelete();
            $table->foreignId('import_row_id')
                ->nullable()
                ->constrained('import_rows')
                ->nullOnDelete();
            $table->string('stage', 32);
            $table->string('severity', 16);
            $table->string('code', 64);
            $table->string('profile', 64)->nullable();
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('field')->nullable();
            $table->text('message');
            $table->text('context_encrypted')->nullable();
            $table->boolean('is_ignorable')->default(false);
            $table->timestamps();

            $table->index(
                ['import_batch_id', 'stage', 'severity'],
                'import_issues_batch_stage_severity_index',
            );
            $table->index(
                ['import_batch_id', 'import_file_id'],
                'import_issues_batch_file_index',
            );
            $table->index(
                ['import_batch_id', 'import_row_id'],
                'import_issues_batch_row_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
    }
};
