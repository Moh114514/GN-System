<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('operation_mode', 32)->default('normal')->after('kind')->index();
            $table->text('operation_reason')->nullable()->after('operation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropIndex(['operation_mode']);
            $table->dropColumn(['operation_mode', 'operation_reason']);
        });
    }
};
