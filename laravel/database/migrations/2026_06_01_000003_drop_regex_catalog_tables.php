<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop all tables that served the old regex-based parsing/normalization pipeline.
 *
 * These tables are no longer needed because:
 *   - catalog_grades / catalog_sub_grades  — dead code, never queried after removal
 *   - catalog_model_generations            — generation codes extracted via regex (removed)
 *   - catalog_trims                        — trim matched via regex in GradeExtractorService (removed)
 *   - catalog_token_maps                   — token→value lookup used by regex pipeline (removed)
 *   - taxonomy_terms                       — dynamic token hints for regex parser (removed)
 *   - taxonomy_rules                       — model/badge CONTAINS pattern rules (removed)
 *   - taxonomy_rule_hits                   — hit tracking for taxonomy_rules (removed)
 *   - taxonomy_anomaly_queue               — unmatched tails from regex parser (removed)
 *
 * Replacement:
 *   catalog_badges — Badge string → {fuel, engine_volume, drive_type} (exact lookup)
 *   translations   — KR → EN/RU display (kept)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // Old grade hierarchy (dead code)
        Schema::dropIfExists('catalog_sub_grades');
        Schema::dropIfExists('catalog_grades');

        // Old regex-based normalization
        Schema::dropIfExists('catalog_model_generations');
        Schema::dropIfExists('catalog_trims');
        Schema::dropIfExists('catalog_token_maps');
        Schema::dropIfExists('taxonomy_terms');

        // Old rule engine
        Schema::dropIfExists('taxonomy_rule_hits');
        Schema::dropIfExists('taxonomy_anomaly_queue');
        Schema::dropIfExists('taxonomy_rules');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // These tables should not be restored — they are replaced by catalog_badges.
        // Re-run old migrations if rollback is truly needed.
    }
};
