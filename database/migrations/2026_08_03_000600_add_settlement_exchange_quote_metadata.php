<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->string('exchange_rate_quote_source', 64)->nullable()->after('exchange_rate_krw_per_cny');
            $table->timestamp('exchange_rate_quoted_at')->nullable()->after('exchange_rate_quote_source');
            $table->string('exchange_rate_quote_status', 24)->default('not_requested')->after('exchange_rate_quoted_at');
            $table->string('exchange_rate_quote_error', 500)->nullable()->after('exchange_rate_quote_status');
            $table->boolean('exchange_rate_manual_override')->default(false)->after('exchange_rate_quote_error');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn([
                'exchange_rate_quote_source',
                'exchange_rate_quoted_at',
                'exchange_rate_quote_status',
                'exchange_rate_quote_error',
                'exchange_rate_manual_override',
            ]);
        });
    }
};
