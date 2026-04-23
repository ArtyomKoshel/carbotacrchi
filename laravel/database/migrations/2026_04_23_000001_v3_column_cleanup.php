<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 column cleanup:
 *   - Drop dead columns: has_keys, document
 *   - Add new columns: seat_count, is_domestic, import_type
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // Drop dead columns (never populated by any parser)
            if (Schema::hasColumn('lots', 'has_keys')) {
                $table->dropColumn('has_keys');
            }
            if (Schema::hasColumn('lots', 'document')) {
                $table->dropColumn('document');
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            // New first-class columns (previously in raw_data or absent)
            $table->unsignedTinyInteger('seat_count')->nullable()->after('engine_volume');
            $table->boolean('is_domestic')->nullable()->after('sell_type_raw');
            $table->string('import_type', 30)->nullable()->after('is_domestic');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['seat_count', 'is_domestic', 'import_type']);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->boolean('has_keys')->nullable();
            $table->string('document', 100)->nullable();
        });
    }
};
