<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_grades', function (Blueprint $table): void {
            $table->dropColumn('monthly_threshold_krw');
        });
    }

    public function down(): void
    {
        Schema::table('policy_grades', function (Blueprint $table): void {
            $table->unsignedBigInteger('monthly_threshold_krw')->default(0)->after('name');
        });
    }
};
