<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $maximums = [];

        DB::table('customers')
            ->select('code')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $customer) use (&$maximums): void {
                if (preg_match('/^(.+)-(\d+)$/', (string) $customer->code, $matches) !== 1) {
                    return;
                }

                $prefix = $matches[1];
                $maximums[$prefix] = max($maximums[$prefix] ?? 0, (int) $matches[2]);
            });

        DB::transaction(function () use ($maximums): void {
            foreach ($maximums as $prefix => $maximum) {
                DB::table('customer_number_sequences')->insertOrIgnore([
                    'prefix' => $prefix,
                    'last_number' => $maximum,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sequence = DB::table('customer_number_sequences')
                    ->where('prefix', $prefix)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lastNumber = max((int) $sequence->last_number, $maximum);

                if ($lastNumber > (int) $sequence->last_number) {
                    DB::table('customer_number_sequences')
                        ->where('prefix', $prefix)
                        ->update(['last_number' => $lastNumber, 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Sequence values are a data repair and must not be rolled back automatically.
    }
};
