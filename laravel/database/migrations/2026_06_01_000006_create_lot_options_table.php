<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_options', function (Blueprint $table) {
            $table->string('lot_id', 50);
            $table->string('option_code', 20);

            $table->primary(['lot_id', 'option_code']);
            $table->index('option_code');
            $table->index('lot_id');
        });

        // Migrate existing options JSON data to the new pivot table
        $batchSize = 500;
        $offset    = 0;

        do {
            $rows = DB::table('lots')
                ->whereNotNull('options')
                ->where('options', '!=', '[]')
                ->where('options', '!=', 'null')
                ->select('id', 'options')
                ->limit($batchSize)
                ->offset($offset)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $inserts = [];
            foreach ($rows as $lot) {
                $codes = json_decode($lot->options, true);
                if (!is_array($codes)) {
                    continue;
                }
                foreach (array_unique($codes) as $code) {
                    $code = trim((string) $code);
                    if ($code === '') {
                        continue;
                    }
                    $inserts[] = ['lot_id' => $lot->id, 'option_code' => $code];
                }
            }

            if (!empty($inserts)) {
                foreach (array_chunk($inserts, 1000) as $chunk) {
                    DB::table('lot_options')->insertOrIgnore($chunk);
                }
            }

            $offset += $batchSize;
        } while ($rows->count() === $batchSize);
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_options');
    }
};
