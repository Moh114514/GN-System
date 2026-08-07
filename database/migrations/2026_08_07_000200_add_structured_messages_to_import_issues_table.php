<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_issues', function (Blueprint $table): void {
            $table->string('message_key', 160)->nullable()->after('message');
            $table->text('message_parameters')->nullable()->after('message_key');
        });
    }

    public function down(): void
    {
        Schema::table('import_issues', function (Blueprint $table): void {
            $table->dropColumn(['message_key', 'message_parameters']);
        });
    }
};
