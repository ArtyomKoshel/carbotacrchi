<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_filters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique()->comment('Rule name, e.g. exclude_rental');
            $table->string('source', 32)->nullable()->comment('Null = all sources; encar/kbcha/... = scoped');
            $table->string('field', 64)->comment('CarLot attribute, supports dotted path');
            $table->string('operator', 32)->comment('eq|ne|gt|gte|lt|lte|in|not_in|between|is_null|is_not_null|contains|regex');
            $table->text('value')->nullable()->comment('JSON-encoded RHS value');
            $table->string('action', 32)->default('skip')->comment('skip|flag|mark_inactive|allow');
            $table->integer('priority')->default(100)->comment('Lower = evaluated first');
            $table->boolean('enabled')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
            $table->index('source');
        });

        // Seed baseline rules matching DEFAULT_RULES in parser/filters/config.py
        $now = now();
        DB::table('parse_filters')->insert([
            ['name' => 'exclude_rental',         'source' => null, 'field' => 'sell_type',       'operator' => 'eq',  'value' => '"rental"',         'action' => 'skip', 'priority' => 10, 'enabled' => true, 'description' => 'Rental disposal vehicles',      'created_at' => $now, 'updated_at' => $now],
            ['name' => 'exclude_under_contract', 'source' => null, 'field' => 'sell_type',       'operator' => 'eq',  'value' => '"under_contract"', 'action' => 'skip', 'priority' => 10, 'enabled' => true, 'description' => 'Lots under contract',          'created_at' => $now, 'updated_at' => $now],
            ['name' => 'exclude_insurance_hide', 'source' => null, 'field' => 'sell_type',       'operator' => 'eq',  'value' => '"insurance_hide"', 'action' => 'skip', 'priority' => 10, 'enabled' => true, 'description' => 'Insurance history hidden',     'created_at' => $now, 'updated_at' => $now],
            ['name' => 'exclude_lease',          'source' => null, 'field' => 'sell_type',       'operator' => 'eq',  'value' => '"lease"',          'action' => 'skip', 'priority' => 20, 'enabled' => true, 'description' => 'Lease vehicles (toggle off to allow)', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'max_mileage',            'source' => null, 'field' => 'mileage',         'operator' => 'gt',  'value' => '200000',           'action' => 'skip', 'priority' => 30, 'enabled' => true, 'description' => 'Mileage > 200,000 km',         'created_at' => $now, 'updated_at' => $now],
            ['name' => 'min_year',               'source' => null, 'field' => 'year',            'operator' => 'lt',  'value' => '2014',             'action' => 'skip', 'priority' => 30, 'enabled' => true, 'description' => 'Year < 2014',                  'created_at' => $now, 'updated_at' => $now],
            ['name' => 'max_insurance_count',    'source' => null, 'field' => 'insurance_count', 'operator' => 'gte', 'value' => '4',                'action' => 'skip', 'priority' => 30, 'enabled' => true, 'description' => '4+ insurance claims',          'created_at' => $now, 'updated_at' => $now],
            ['name' => 'absurd_price',           'source' => null, 'field' => 'price',           'operator' => 'gte', 'value' => '1000000000',       'action' => 'skip', 'priority' => 5,  'enabled' => true, 'description' => 'Price >= 1,000,000,000 KRW',   'created_at' => $now, 'updated_at' => $now],
            ['name' => 'zero_price',             'source' => null, 'field' => 'price',           'operator' => 'lte', 'value' => '0',                'action' => 'skip', 'priority' => 5,  'enabled' => true, 'description' => 'Price <= 0',                   'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_filters');
    }
};
