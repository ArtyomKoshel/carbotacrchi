<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the pivot tables replaced by lots.options_mask / lots.photo_*.
 *
 * Deploy only AFTER the previous release (2026_10_19_000001 / _000002) has
 * been live and `php artisan lots:compact-backfill` has finished on prod.
 * Refuses to run while the backfill is incomplete, so lot data is never lost.
 * DROP TABLE is metadata-only in MySQL 8+, fast enough for the start-up window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lot_options')) {
            $pending = DB::table('lots')
                ->whereNull('options_mask')
                ->whereRaw('JSON_LENGTH(options) > 0')
                ->count();
            if ($pending > 0) {
                throw new RuntimeException(
                    "{$pending} lots have options but no options_mask — run `php artisan lots:compact-backfill --only=options` first."
                );
            }
        }

        if (Schema::hasTable('lot_photos')) {
            $pending = DB::table('lots')
                ->whereNull('photo_codes')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('lot_photos')->whereColumn('lot_photos.lot_id', 'lots.id'))
                ->count();
            if ($pending > 0) {
                throw new RuntimeException(
                    "{$pending} lots have lot_photos rows but no photo_codes — run `php artisan lots:compact-backfill --only=photos` first."
                );
            }
        }

        Schema::dropIfExists('lot_options');
        Schema::dropIfExists('lot_photos');
    }

    public function down(): void
    {
        // Not restorable: the data now lives in lots.options_mask / lots.photo_*.
        // Re-create the empty tables only if old code has to run again.
    }
};
