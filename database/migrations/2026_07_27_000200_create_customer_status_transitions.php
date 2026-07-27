<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_status_id')->constrained('customer_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('customer_statuses')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['from_status_id', 'to_status_id']);
        });

        $statusIds = DB::table('customer_statuses')->orderBy('sort_order')->pluck('id')->all();
        foreach (array_map(null, array_slice($statusIds, 0, -1), array_slice($statusIds, 1)) as [$fromStatusId, $toStatusId]) {
            DB::table('customer_status_transitions')->insert([
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_status_transitions');
    }
};
