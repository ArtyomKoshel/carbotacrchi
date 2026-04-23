<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Normalize legacy vocabulary values in `lots` to the canonical lowercase form
 * defined in parser/parsers/_shared/vocabulary.py.
 *
 * Before this migration:
 *   - Encar wrote drive_type='awd' (lowercase)
 *   - KBCha wrote drive_type='AWD' (uppercase)
 *   - Body type: Encar 'sedan' vs KBCha 'Sedan'
 *   - Fuel: KBCha sometimes 'Gasoline' (capitalized) before late patches
 *
 * After this migration, all rows use the same canonical lowercase values so
 * filter rules like `drive_type eq 'awd'` hit every parser uniformly.
 *
 * Also collapses '4wd' → 'awd' per the unified vocabulary decision (Korean
 * market treats 4WD / AWD interchangeably; see vocabulary.py ENCAR_DRIVE).
 */
return new class extends Migration
{
    /**
     * No-op: the old version of this migration ran `UPDATE lots SET col=LOWER(col)`
     * across five columns. On a 226k-row table that is five full-table rewrites
     * taking minutes to tens of minutes and blowing the undo log — unacceptable
     * while the server is live.
     *
     * Data normalization now happens organically: every lot eventually gets
     * re-upserted by the parser, at which point the canonical lowercase
     * vocabulary.py values overwrite the legacy ones via the upsert's
     * COALESCE(VALUES(col), col) clause. A full cycle takes ~2–4 hours.
     *
     * This migration is kept (not deleted) so the batch-number sequence stays
     * stable across already-deployed environments.
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
