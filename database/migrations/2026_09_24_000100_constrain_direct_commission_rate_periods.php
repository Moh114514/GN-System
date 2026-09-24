<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $overlaps = DB::table('direct_commission_rates as earlier')
            ->join('direct_commission_rates as later', 'earlier.id', '<', 'later.id')
            ->whereRaw("daterange(earlier.effective_from, COALESCE(earlier.effective_until + 1, 'infinity'::date), '[)') && daterange(later.effective_from, COALESCE(later.effective_until + 1, 'infinity'::date), '[)')")
            ->exists();
        if ($overlaps) {
            throw new RuntimeException('Cannot constrain direct commission rates: existing effective periods overlap. Resolve the rate history before migrating.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE direct_commission_rates ADD CONSTRAINT direct_commission_rates_effective_overlap_exclude EXCLUDE USING gist (daterange(effective_from, COALESCE(effective_until + 1, 'infinity'::date), '[)') WITH &&)");
        DB::statement('ALTER TABLE direct_commission_rates ADD CONSTRAINT direct_commission_rates_effective_from_unique UNIQUE (effective_from)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE direct_commission_rates DROP CONSTRAINT direct_commission_rates_effective_from_unique');
        DB::statement('ALTER TABLE direct_commission_rates DROP CONSTRAINT direct_commission_rates_effective_overlap_exclude');
    }
};
