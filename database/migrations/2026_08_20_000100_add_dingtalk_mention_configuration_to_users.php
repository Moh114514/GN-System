<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('dingtalk_mention_type', 16)->nullable()->after('email');
            $table->string('dingtalk_mention_value')->nullable()->after('dingtalk_mention_type');
        });

        DB::table('users')
            ->whereNotNull('dingtalk_user_id')
            ->where('dingtalk_user_id', '<>', '')
            ->update([
                'dingtalk_mention_type' => 'user_id',
                'dingtalk_mention_value' => DB::raw('dingtalk_user_id'),
            ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dingtalk_user_id');
        });
    }

    public function down(): void
    {
        $mobileBindings = DB::table('users')
            ->where('dingtalk_mention_type', 'mobile')
            ->whereNotNull('dingtalk_mention_value')
            ->where('dingtalk_mention_value', '<>', '')
            ->count();
        if ($mobileBindings > 0) {
            throw new RuntimeException('Cannot roll back DingTalk mention migration while mobile bindings exist; export or clear them first.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('dingtalk_user_id')->nullable()->after('email');
        });

        DB::table('users')
            ->where('dingtalk_mention_type', 'user_id')
            ->whereNotNull('dingtalk_mention_value')
            ->update([
                'dingtalk_user_id' => DB::raw('dingtalk_mention_value'),
            ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['dingtalk_mention_type', 'dingtalk_mention_value']);
        });
    }
};
