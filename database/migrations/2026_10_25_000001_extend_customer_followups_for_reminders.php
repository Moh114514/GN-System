<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followup_records', function (Blueprint $table): void {
            $table->text('result')->nullable()->after('content');
            $table->foreignId('reminder_id')->nullable()->after('owner_id')->constrained('reminders')->nullOnDelete();
            $table->unique('reminder_id');
        });
    }

    public function down(): void
    {
        Schema::table('followup_records', function (Blueprint $table): void {
            $table->dropUnique(['reminder_id']);
            $table->dropConstrainedForeignId('reminder_id');
            $table->dropColumn('result');
        });
    }
};
