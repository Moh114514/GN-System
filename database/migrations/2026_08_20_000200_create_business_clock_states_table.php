<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_clock_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->timestamp('simulated_at')->nullable();
            $table->string('mode', 16)->default('real');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });

        $now = CarbonImmutable::now((string) config('app.timezone'));
        $state = [
            'id' => 1,
            'enabled' => false,
            'simulated_at' => null,
            'mode' => 'real',
            'changed_by' => null,
            'changed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ((bool) Cache::get('gn:test-clock:enabled', false)) {
            $legacyNow = Cache::get('gn:test-clock:now');
            if (! is_string($legacyNow) || trim($legacyNow) === '') {
                throw new RuntimeException('Cannot migrate the active legacy business clock without a timestamp.');
            }
            try {
                $simulatedAt = CarbonImmutable::parse($legacyNow, (string) config('app.timezone'));
            } catch (Throwable $exception) {
                throw new RuntimeException('Cannot migrate the active legacy business clock timestamp.', 0, $exception);
            }
            $state['enabled'] = true;
            $state['simulated_at'] = $simulatedAt;
            $state['mode'] = 'frozen';
            $state['changed_at'] = $now;
        }
        DB::table('business_clock_states')->insert($state);
        Cache::forget('gn:test-clock:enabled');
        Cache::forget('gn:test-clock:now');
    }

    public function down(): void
    {
        Schema::dropIfExists('business_clock_states');
    }
};
