<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lot's photo gallery as a URL template on `lots` instead of the
 * `lot_photos` table (8.3M rows, ~2.1 GB). See App\Support\LotPhotos.
 *
 *   photo_dir        carpicture{NN}
 *   photo_base_id    photo id in the file name (≠ lot id for relists)
 *   photo_codes      ordered "021,001,009"; '' when photo_urls is used
 *   photo_ext_upper  .JPG instead of .jpg
 *   photo_urls       verbatim JSON list for anything off-template
 *
 * DDL only (INSTANT). Fill from lot_photos with
 *   php artisan lots:compact-backfill --only=photos
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lots', 'photo_codes')) {
            return;
        }
        Schema::table('lots', function (Blueprint $table) {
            $table->unsignedTinyInteger('photo_dir')->nullable();
            $table->unsignedInteger('photo_base_id')->nullable();
            $table->string('photo_codes', 1000)->nullable();
            $table->boolean('photo_ext_upper')->default(false);
            $table->json('photo_urls')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('lots', 'photo_codes')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn(['photo_dir', 'photo_base_id', 'photo_codes', 'photo_ext_upper', 'photo_urls']);
            });
        }
    }
};
