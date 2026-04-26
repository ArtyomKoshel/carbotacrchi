<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $fields = [
        'fuel_economy',
        'mileage_grade',
        'secondary_damage',
        'tax_paid',
        'warranty_text',
        'cylinders',
        'damage',
    ];

    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'fuel_economy')) {
                $table->dropColumn('fuel_economy');
            }
            if (Schema::hasColumn('lots', 'mileage_grade')) {
                $table->dropColumn('mileage_grade');
            }
            if (Schema::hasColumn('lots', 'secondary_damage')) {
                $table->dropColumn('secondary_damage');
            }
            if (Schema::hasColumn('lots', 'tax_paid')) {
                $table->dropColumn('tax_paid');
            }
            if (Schema::hasColumn('lots', 'warranty_text')) {
                $table->dropColumn('warranty_text');
            }
            if (Schema::hasColumn('lots', 'cylinders')) {
                $table->dropColumn('cylinders');
            }
            if (Schema::hasColumn('lots', 'damage')) {
                $table->dropColumn('damage');
            }
        });

        if (Schema::hasTable('field_coverage_stats')) {
            DB::table('field_coverage_stats')->whereIn('field_name', $this->fields)->delete();
        }
        if (Schema::hasTable('parse_filters')) {
            DB::table('parse_filters')->whereIn('field', $this->fields)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'fuel_economy')) {
                $table->decimal('fuel_economy', 5, 1)->unsigned()->nullable()->after('engine_volume');
            }
            if (!Schema::hasColumn('lots', 'mileage_grade')) {
                $table->string('mileage_grade', 20)->nullable()->after('insurance_count');
            }
            if (!Schema::hasColumn('lots', 'tax_paid')) {
                $table->boolean('tax_paid')->nullable()->after('seizure_status');
            }
            if (!Schema::hasColumn('lots', 'warranty_text')) {
                $table->string('warranty_text', 80)->nullable()->after('paid_options');
            }
            if (!Schema::hasColumn('lots', 'cylinders')) {
                $table->tinyInteger('cylinders')->unsigned()->nullable()->after('engine_volume');
            }
            if (!Schema::hasColumn('lots', 'damage')) {
                $table->text('damage')->nullable()->after('drive_type');
            }
            if (!Schema::hasColumn('lots', 'secondary_damage')) {
                $table->text('secondary_damage')->nullable()->after('damage');
            }
        });
    }
};
