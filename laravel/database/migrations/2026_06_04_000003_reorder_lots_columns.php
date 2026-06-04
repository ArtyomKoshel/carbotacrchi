<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder lots columns into logical groups:
 *
 *  1. Identity          id, source
 *  2. iNav hierarchy    make / make_en, model_group / model_group_en,
 *                       model / model_en, badge_group / badge_group_en,
 *                       badge / badge_en, trim / trim_en
 *  3. Dates             year, first_reg_date, listed_at
 *  4. Price             price, retail_value, repair_cost
 *  5. Tech specs        mileage, engine_volume, fuel, transmission,
 *                       body_type, drive_type, seat_count, color, seat_color
 *  6. Condition         has_accident, flood_history, total_loss_history,
 *                       owners_count, insurance_count
 *  7. Legal             lien_status, seizure_status
 *  8. Location/docs     location, vin, plate_number
 *  9. Options           options, paid_options
 * 10. Links             image_url, lot_url
 * 11. Sales             sell_type, sell_type_raw
 * 12. System            is_active, raw_data, parsed_at, fetched_at,
 *                       expires_at, created_at, updated_at
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lots')) {
            return;
        }

        DB::statement("
            ALTER TABLE lots
              -- 2. iNav hierarchy
              MODIFY COLUMN make              VARCHAR(60)   NOT NULL           AFTER source,
              MODIFY COLUMN make_en           VARCHAR(100)  NULL               AFTER make,
              MODIFY COLUMN model_group       VARCHAR(100)  NULL               AFTER make_en,
              MODIFY COLUMN model_group_en    VARCHAR(100)  NULL               AFTER model_group,
              MODIFY COLUMN model             VARCHAR(60)   NOT NULL           AFTER model_group_en,
              MODIFY COLUMN model_en          VARCHAR(100)  NULL               AFTER model,
              MODIFY COLUMN badge_group       VARCHAR(150)  NULL               AFTER model_en,
              MODIFY COLUMN badge_group_en    VARCHAR(150)  NULL               AFTER badge_group,
              MODIFY COLUMN badge             VARCHAR(150)  NULL               AFTER badge_group_en,
              MODIFY COLUMN badge_en          VARCHAR(200)  NULL               AFTER badge,
              MODIFY COLUMN trim              VARCHAR(40)   NULL               AFTER badge_en,
              MODIFY COLUMN trim_en           VARCHAR(150)  NULL               AFTER trim,

              -- 3. Dates
              MODIFY COLUMN year              SMALLINT UNSIGNED NOT NULL       AFTER trim_en,
              MODIFY COLUMN first_reg_date    VARCHAR(30)   NULL               AFTER year,
              MODIFY COLUMN listed_at         DATE          NULL               AFTER first_reg_date,

              -- 4. Price
              MODIFY COLUMN price             BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER listed_at,
              MODIFY COLUMN retail_value      INT UNSIGNED  NULL               AFTER price,
              MODIFY COLUMN repair_cost       INT UNSIGNED  NULL               AFTER retail_value,

              -- 5. Tech specs
              MODIFY COLUMN mileage           INT UNSIGNED  NOT NULL DEFAULT 0 AFTER repair_cost,
              MODIFY COLUMN engine_volume     DOUBLE        NULL               AFTER mileage,
              MODIFY COLUMN fuel              VARCHAR(20)   NULL               AFTER engine_volume,
              MODIFY COLUMN transmission      VARCHAR(20)   NULL               AFTER fuel,
              MODIFY COLUMN body_type         VARCHAR(30)   NULL               AFTER transmission,
              MODIFY COLUMN drive_type        VARCHAR(10)   NULL               AFTER body_type,
              MODIFY COLUMN seat_count        TINYINT UNSIGNED NULL            AFTER drive_type,
              MODIFY COLUMN color             VARCHAR(30)   NULL               AFTER seat_count,
              MODIFY COLUMN seat_color        VARCHAR(30)   NULL               AFTER color,

              -- 6. Condition / history
              MODIFY COLUMN has_accident      TINYINT(1)    NULL               AFTER seat_color,
              MODIFY COLUMN flood_history     TINYINT(1)    NULL               AFTER has_accident,
              MODIFY COLUMN total_loss_history TINYINT(1)   NULL               AFTER flood_history,
              MODIFY COLUMN owners_count      TINYINT UNSIGNED NULL            AFTER total_loss_history,
              MODIFY COLUMN insurance_count   TINYINT UNSIGNED NULL            AFTER owners_count,

              -- 7. Legal
              MODIFY COLUMN lien_status       VARCHAR(30)   NULL               AFTER insurance_count,
              MODIFY COLUMN seizure_status    VARCHAR(30)   NULL               AFTER lien_status,

              -- 8. Location / documents
              MODIFY COLUMN location          VARCHAR(120)  NULL               AFTER seizure_status,
              MODIFY COLUMN vin               VARCHAR(17)   NULL               AFTER location,
              MODIFY COLUMN plate_number      VARCHAR(20)   NULL               AFTER vin,

              -- 9. Options
              MODIFY COLUMN options           JSON          NULL               AFTER plate_number,
              MODIFY COLUMN paid_options      JSON          NULL               AFTER options,

              -- 10. Links / media
              MODIFY COLUMN image_url         VARCHAR(500)  NULL               AFTER paid_options,
              MODIFY COLUMN lot_url           VARCHAR(500)  NULL               AFTER image_url,

              -- 11. Sales
              MODIFY COLUMN sell_type         VARCHAR(32)   NULL               AFTER lot_url,
              MODIFY COLUMN sell_type_raw     VARCHAR(255)  NULL               AFTER sell_type,

              -- 12. System
              MODIFY COLUMN is_active         TINYINT(1)    NOT NULL DEFAULT 1 AFTER sell_type_raw,
              MODIFY COLUMN raw_data          JSON          NULL               AFTER is_active,
              MODIFY COLUMN parsed_at         TIMESTAMP     NULL               AFTER raw_data,
              MODIFY COLUMN fetched_at        TIMESTAMP     NULL               AFTER parsed_at,
              MODIFY COLUMN expires_at        TIMESTAMP     NULL               AFTER fetched_at,
              MODIFY COLUMN created_at        TIMESTAMP     NULL               AFTER expires_at,
              MODIFY COLUMN updated_at        TIMESTAMP     NULL               AFTER created_at
        ");
    }

    public function down(): void
    {
        // Column reorder is cosmetic — no rollback needed
    }
};
