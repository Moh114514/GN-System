<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('failure_reason_key', 160)->nullable()->after('failure_reason');
            $table->jsonb('failure_reason_parameters')->nullable()->after('failure_reason_key');
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->string('exchange_rate_quote_error_key', 160)->nullable()->after('exchange_rate_quote_error');
            $table->jsonb('exchange_rate_quote_error_parameters')->nullable()->after('exchange_rate_quote_error_key');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_quote_error_key', 'exchange_rate_quote_error_parameters']);
        });
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropColumn(['failure_reason_key', 'failure_reason_parameters']);
        });
    }
};
