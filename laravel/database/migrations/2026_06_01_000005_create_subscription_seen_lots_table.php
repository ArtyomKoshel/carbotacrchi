<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_seen_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id');
            $table->string('lot_id', 50);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['subscription_id', 'lot_id']);
            $table->index('subscription_id');

            $table->foreign('subscription_id')
                ->references('id')->on('subscriptions')
                ->cascadeOnDelete();
        });

        // Migrate existing known_lot_ids JSON data to the new table
        $subs = DB::table('subscriptions')
            ->whereNotNull('known_lot_ids')
            ->select('id', 'known_lot_ids')
            ->get();

        foreach ($subs as $sub) {
            $ids = json_decode($sub->known_lot_ids, true);
            if (!is_array($ids) || empty($ids)) {
                continue;
            }

            $rows = array_map(fn ($lotId) => [
                'subscription_id' => $sub->id,
                'lot_id'          => (string) $lotId,
                'created_at'      => now(),
            ], array_unique($ids));

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('subscription_seen_lots')->insertOrIgnore($chunk);
            }
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('known_lot_ids');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('known_lot_ids')->nullable();
        });

        Schema::dropIfExists('subscription_seen_lots');
    }
};
