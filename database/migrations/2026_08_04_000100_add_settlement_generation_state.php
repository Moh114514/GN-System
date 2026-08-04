<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->string('generation_status', 24)->default('pending')->after('status');
            $table->timestamp('generated_at')->nullable()->after('generation_status');
            $table->unsignedInteger('item_count')->default(0)->after('generated_at');
            $table->timestamp('exchange_rate_quote_attempted_at')->nullable()->after('exchange_rate_quoted_at');
        });

    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn([
                'generation_status',
                'generated_at',
                'item_count',
                'exchange_rate_quote_attempted_at',
            ]);
        });
    }
};
