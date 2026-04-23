<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Clean up duplicate / obsolete keys from `lots.raw_data` JSON blob.
 *
 * Problem:
 *   Before this migration each row carried a raw_data JSON with keys already
 *   available elsewhere:
 *     - "photos"        — stored again in the `lot_photos` table
 *     - "photo_path"    — superseded by the `image_url` column
 *     - "photo_count"   — denormalized, can be derived from lot_photos
 *     - "sell_type"     — duplicate of the `sell_type_raw` column (P1)
 *     - "origin_price"  — MSRP in 만원; now promoted to `retail_value` (KRW)
 *
 *   A 20-photo Encar lot carried ~2–3 KB of redundant JSON. Multiplied across
 *   100k+ lots this was a significant table size inflation.
 *
 * Fix:
 *   - Promote origin_price → retail_value (if not set)
 *   - Strip the obsolete keys from raw_data
 *
 * Going forward parsers use `CarLot._RAW_DATA_BLOCKLIST` to prevent
 * re-introduction of these keys.
 */
return new class extends Migration
{
    /**
     * No-op: the previous version ran
     *   UPDATE lots SET raw_data = JSON_REMOVE(raw_data, ...)
     * across every row, which is a full-table rewrite AND touches the JSON
     * blob (~2–3 KB per row). On 226k rows this locks the table for many
     * minutes and bloats the undo log.
     *
     * Why it's safe to skip:
     *   - The parser now writes raw_data via `CarLot._clean_raw_data()` which
     *     applies `_RAW_DATA_BLOCKLIST` before serialization.
     *   - The upsert statement uses `raw_data = VALUES(raw_data)` — the whole
     *     blob is replaced on every re-scrape, not merged. So stale keys die
     *     naturally within one full parser cycle (~2–4 h).
     *
     * origin_price → retail_value back-fill is also skipped — the parser's
     * next upsert will write the new retail_value directly.
     */
    public function up(): void
    {
        // intentionally empty
    }

    public function down(): void
    {
        // intentionally empty
    }
};
