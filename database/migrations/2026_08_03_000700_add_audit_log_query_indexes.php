<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'activity_log_created_at_id_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_at_index');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_log_subject_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_created_at_id_index');
            $table->dropIndex('activity_log_causer_created_at_index');
            $table->dropIndex('activity_log_subject_created_at_index');
        });
    }
};
