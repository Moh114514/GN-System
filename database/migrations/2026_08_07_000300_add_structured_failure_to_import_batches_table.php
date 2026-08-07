<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('failure_reason_key', 160)->nullable()->after('failure_reason');
            $table->text('failure_reason_parameters')->nullable()->after('failure_reason_key');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn(['failure_reason_key', 'failure_reason_parameters']);
        });
    }
};
