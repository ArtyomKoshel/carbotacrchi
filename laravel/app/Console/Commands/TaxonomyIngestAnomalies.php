<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Services\Taxonomy\TaxonomySuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TaxonomyIngestAnomalies extends Command
{
    protected $signature = 'taxonomy:ingest-anomalies
        {--source=encar : Source to ingest}
        {--max=50000 : Max lines to process from tail}
        {--file= : Optional explicit JSONL file path}';

    protected $description = 'Ingest parser taxonomy anomaly JSONL into taxonomy_anomaly_queue with suggestions';

    public function handle(TaxonomySuggestionService $suggestions): int
    {
        $source = trim((string) $this->option('source')) ?: 'encar';
        $max = max(1, (int) $this->option('max'));
        $file = trim((string) $this->option('file'));

        if ($file === '') {
            $base = (string) config('admin.log_file', '');
            if ($base === '') {
                $this->error('admin.log_file is not configured');
                return self::FAILURE;
            }
            $file = dirname($base) . DIRECTORY_SEPARATOR . 'taxonomy_anomalies.jsonl';
        }

        if (!is_file($file)) {
            $this->error("Anomaly file not found: {$file}");
            return self::FAILURE;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $this->error('Cannot read anomaly file');
            return self::FAILURE;
        }

        $slice = array_slice($lines, -$max);
        $ingested = 0;
        $updated = 0;

        foreach ($slice as $line) {
            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                continue;
            }

            $rowSource = trim((string) ($payload['source'] ?? $source));
            if ($rowSource !== $source) {
                continue;
            }

            $make = $this->nullable($payload['make_kr'] ?? null);
            $unknownTail = $this->nullable($payload['unknown_tail'] ?? null);
            if ($unknownTail === null) {
                continue;
            }

            $reason = $this->nullable($payload['reason'] ?? null);
            $lotId = $this->nullable($payload['lot_id'] ?? null);
            $modelRaw = $this->nullable($payload['model_raw'] ?? null);
            $ts = $this->parseTs($payload['ts'] ?? null);

            $suggested = $suggestions->suggest($unknownTail, $modelRaw);

            $existing = TaxonomyAnomalyQueue::query()
                ->where('source', $rowSource)
                ->where('make', $make)
                ->where('unknown_tail', $unknownTail)
                ->where('reason', $reason)
                ->first();

            if ($existing) {
                $existing->seen_count = (int) $existing->seen_count + 1;
                $existing->last_seen_at = $ts;
                if ($existing->sample_lot_id === null && $lotId !== null) {
                    $existing->sample_lot_id = $lotId;
                }
                if ($existing->sample_model_raw === null && $modelRaw !== null) {
                    $existing->sample_model_raw = $modelRaw;
                }
                if (($existing->suggested_action === null || $existing->suggested_action === '') && !empty($suggested['action'])) {
                    $existing->suggested_action = $suggested['action'];
                    $existing->suggested_value = $suggested['value'];
                    $existing->suggestion_confidence = $suggested['confidence'];
                }
                $existing->save();
                $updated++;
                continue;
            }

            TaxonomyAnomalyQueue::query()->create([
                'source' => $rowSource,
                'make' => $make,
                'unknown_tail' => $unknownTail,
                'reason' => $reason,
                'sample_lot_id' => $lotId,
                'sample_model_raw' => $modelRaw,
                'sample_payload' => $payload,
                'seen_count' => 1,
                'first_seen_at' => $ts,
                'last_seen_at' => $ts,
                'status' => 'new',
                'suggested_action' => $suggested['action'],
                'suggested_value' => $suggested['value'],
                'suggestion_confidence' => $suggested['confidence'],
            ]);
            $ingested++;
        }

        $this->info("taxonomy anomalies ingest done: inserted={$ingested}, updated={$updated}, source={$source}");

        return self::SUCCESS;
    }

    private function nullable(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function parseTs(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return now()->toDateTimeString();
        }

        try {
            return Carbon::parse($s)->toDateTimeString();
        } catch (\Throwable) {
            return now()->toDateTimeString();
        }
    }
}
