<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * catalog_drive_tokens — maps badge token → drive_type.
 *
 * Used in normalization (Step 4b): when lots.drive_type is NULL,
 * scan lots.badge_en for these tokens and fill drive_type.
 *
 * Also serves as a search vocabulary: "xDrive" → awd, "quattro" → awd, etc.
 * The ChatSearchService AI prompt includes these so users can search by brand-
 * specific terms ("BMW xDrive", "Audi quattro") and get correct AWD results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_drive_tokens', function (Blueprint $table) {
            $table->string('token', 30)->primary();
            $table->string('drive_type', 10);
            $table->timestamps();
        });

        $now = now();
        DB::table('catalog_drive_tokens')->insert([
            // AWD tokens
            ['token' => 'xDrive',   'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '4MATIC',   'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '4MATIC+',  'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'quattro',  'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'AWD',      'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '4WD',      'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '4xe',      'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'e-4WD',    'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'HTRAC',    'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'ALL4',     'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '4드라이브', 'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => '콰트로',   'drive_type' => 'awd', 'created_at' => $now, 'updated_at' => $now],
            // RWD tokens
            ['token' => 'sDrive',   'drive_type' => 'rwd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'RWD',      'drive_type' => 'rwd', 'created_at' => $now, 'updated_at' => $now],
            // FWD tokens
            ['token' => '2WD',      'drive_type' => 'fwd', 'created_at' => $now, 'updated_at' => $now],
            ['token' => 'FWD',      'drive_type' => 'fwd', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_drive_tokens');
    }
};
