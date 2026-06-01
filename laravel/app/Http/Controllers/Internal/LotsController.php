<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LotsController extends Controller
{
    /**
     * Upsert a batch of lots from the parser.
     * Validates the payload structure, upserts to DB, syncs lot_options pivot.
     *
     * POST /internal/lots/upsert
     * Header: X-Internal-Token: <token>
     * Body: {"source": "encar", "lots": [...]}
     */
    public function upsert(Request $request): JsonResponse
    {
        $source = $request->input('source');
        $lots   = $request->input('lots', []);

        if (!in_array($source, ['encar', 'kbcha'], true)) {
            return response()->json(['ok' => false, 'error' => 'Invalid source'], 422);
        }

        if (!is_array($lots) || empty($lots)) {
            return response()->json(['ok' => false, 'error' => 'No lots provided'], 422);
        }

        $inserted = 0;
        $updated  = 0;
        $errors   = 0;
        $now      = now()->toDateTimeString();

        foreach (array_chunk($lots, 100) as $batch) {
            try {
                [$batchInserted, $batchUpdated] = $this->upsertBatch($source, $batch, $now);
                $inserted += $batchInserted;
                $updated  += $batchUpdated;
            } catch (\Throwable $e) {
                Log::error('[internal/lots] batch upsert failed: ' . $e->getMessage());
                $errors++;
            }
        }

        return response()->json([
            'ok'   => true,
            'data' => ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors],
        ]);
    }

    /**
     * Mark lots as inactive (delisted).
     *
     * POST /internal/lots/delist
     * Body: {"source": "encar", "lot_ids": [...], "reason": "not_seen"}
     */
    public function delist(Request $request): JsonResponse
    {
        $source  = $request->input('source');
        $lotIds  = $request->input('lot_ids', []);
        $reason  = $request->input('reason', 'not_seen');

        if (!in_array($source, ['encar', 'kbcha'], true) || empty($lotIds)) {
            return response()->json(['ok' => false, 'error' => 'Invalid input'], 422);
        }

        $count = 0;
        foreach (array_chunk($lotIds, 500) as $chunk) {
            $count += DB::table('lots')
                ->where('source', $source)
                ->whereIn('id', $chunk)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        Log::info("[internal/lots] delisted {$count} lots from {$source} (reason: {$reason})");

        return response()->json(['ok' => true, 'data' => ['delisted' => $count]]);
    }

    private function upsertBatch(string $source, array $lots, string $now): array
    {
        $existingIds = DB::table('lots')
            ->whereIn('id', array_column($lots, 'id'))
            ->pluck('id')
            ->flip()
            ->all();

        $inserted = 0;
        $updated  = 0;

        foreach ($lots as $lot) {
            $id = (string) ($lot['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $row = $this->buildRow($lot, $source, $now);

            if (isset($existingIds[$id])) {
                DB::table('lots')->where('id', $id)->update(array_merge($row, ['updated_at' => $now]));
                $updated++;
            } else {
                DB::table('lots')->insertOrIgnore(array_merge($row, [
                    'id'         => $id,
                    'source'     => $source,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $inserted++;
            }

            // Sync lot_options pivot
            $codes = $lot['options'] ?? [];
            if (is_array($codes) && !empty($codes)) {
                DB::table('lot_options')->where('lot_id', $id)->delete();
                $optRows = array_map(fn ($c) => ['lot_id' => $id, 'option_code' => (string) $c], array_unique($codes));
                foreach (array_chunk($optRows, 200) as $chunk) {
                    DB::table('lot_options')->insertOrIgnore($chunk);
                }
            }
        }

        return [$inserted, $updated];
    }

    private function buildRow(array $lot, string $source, string $now): array
    {
        $json = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? null);

        return [
            'source'                  => $source,
            'make'                    => $lot['make']             ?? null,
            'model'                   => $lot['model']            ?? null,
            'model_en'                => $lot['model_en']         ?? null,
            'model_group'             => $lot['model_group']      ?? null,
            'generation'              => $lot['generation']       ?? null,
            'variant'                 => $lot['variant']          ?? null,
            'year'                    => isset($lot['year'])    ? (int) $lot['year']    : null,
            'price'                   => isset($lot['price'])   ? (int) $lot['price']   : null,
            'mileage'                 => isset($lot['mileage']) ? (int) $lot['mileage'] : null,
            'vin'                     => $lot['vin']              ?? null,
            'body_type'               => $lot['body_type']        ?? null,
            'transmission'            => $lot['transmission']     ?? null,
            'fuel'                    => $lot['fuel']             ?? null,
            'drive_type'              => $lot['drive_type']       ?? null,
            'engine_volume'           => isset($lot['engine_volume']) ? (float) $lot['engine_volume'] : null,
            'cylinders'               => isset($lot['cylinders']) ? (int) $lot['cylinders'] : null,
            'color'                   => $lot['color']            ?? null,
            'seat_color'              => $lot['seat_color']       ?? null,
            'trim'                    => $lot['trim']             ?? null,
            'trim_en'                 => $lot['trim_en']          ?? null,
            'package'                 => $lot['package']          ?? null,
            'badge'                   => $lot['badge']            ?? null,
            'location'                => $lot['location']         ?? null,
            'image_url'               => $lot['image_url']        ?? null,
            'lot_url'                 => $lot['lot_url']          ?? null,
            'lien_status'             => $lot['lien_status']      ?? null,
            'seizure_status'          => $lot['seizure_status']   ?? null,
            'sell_type'               => $lot['sell_type']        ?? null,
            'sell_type_raw'           => $lot['sell_type_raw']    ?? null,
            'has_accident'            => isset($lot['has_accident']) ? (int) (bool) $lot['has_accident'] : null,
            'flood_history'           => isset($lot['flood_history']) ? (int) (bool) $lot['flood_history'] : null,
            'total_loss_history'      => isset($lot['total_loss_history']) ? (int) (bool) $lot['total_loss_history'] : null,
            'owners_count'            => isset($lot['owners_count'])    ? (int) $lot['owners_count']    : null,
            'insurance_count'         => isset($lot['insurance_count']) ? (int) $lot['insurance_count'] : null,
            'seat_count'              => isset($lot['seat_count'])      ? (int) $lot['seat_count']      : null,
            'retail_value'            => isset($lot['retail_value'])    ? (int) $lot['retail_value']    : null,
            'repair_cost'             => isset($lot['repair_cost'])     ? (int) $lot['repair_cost']     : null,
            'registration_year_month' => isset($lot['registration_year_month']) ? (int) $lot['registration_year_month'] : null,
            'plate_number'            => $lot['plate_number']     ?? null,
            'first_reg_date'          => $lot['first_reg_date']   ?? null,
            'listed_at'               => $lot['listed_at']        ?? null,
            'options'                 => $json($lot['options']    ?? null),
            'paid_options'            => $json($lot['paid_options'] ?? null),
            'raw_data'                => $json($lot['raw_data']   ?? null),
            'is_active'               => (int) ($lot['is_active'] ?? 1),
            'parsed_at'               => $now,
        ];
    }
}
