<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Models\TaxonomyRule;
use Illuminate\Console\Command;

class TaxonomyBootstrapRules extends Command
{
    protected $signature = 'taxonomy:bootstrap-rules
        {--source=encar : Source filter}
        {--min-seen=5 : Minimum anomaly seen_count}
        {--min-confidence=0.80 : Minimum suggestion confidence}
        {--actions=set_trim : CSV of allowed suggested actions}
        {--apply : Persist rules (default is dry-run)}';

    protected $description = 'Create baseline taxonomy rules from anomaly queue suggestions';

    public function handle(): int
    {
        $source = trim((string) $this->option('source')) ?: 'encar';
        $minSeen = max(1, (int) $this->option('min-seen'));
        $minConfidence = max(0.0, min(1.0, (float) $this->option('min-confidence')));
        $apply = (bool) $this->option('apply');

        $actions = array_values(array_filter(array_map(
            static fn (string $v): string => trim($v),
            explode(',', (string) $this->option('actions'))
        )));
        if ($actions === []) {
            $actions = ['set_trim'];
        }

        $this->info('Taxonomy rules bootstrap');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line("source={$source}, min_seen={$minSeen}, min_confidence={$minConfidence}, actions=" . implode(',', $actions));

        $rows = TaxonomyAnomalyQueue::query()
            ->where('source', $source)
            ->where('status', 'new')
            ->where('seen_count', '>=', $minSeen)
            ->where('suggestion_confidence', '>=', $minConfidence)
            ->whereIn('suggested_action', $actions)
            ->orderByDesc('seen_count')
            ->orderByDesc('id')
            ->get();

        $candidates = 0;
        $created = 0;
        $skippedExisting = 0;

        foreach ($rows as $row) {
            $action = trim((string) $row->suggested_action);
            $value = trim((string) ($row->suggested_value ?? ''));
            if ($action === '') {
                continue;
            }

            $exists = TaxonomyRule::query()
                ->where('source', $row->source)
                ->where('make', $row->make)
                ->where('unknown_tail', $row->unknown_tail)
                ->where('action', $action)
                ->where('action_value', $value !== '' ? $value : null)
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                $skippedExisting++;
                continue;
            }

            $candidates++;
            if (!$apply) {
                continue;
            }

            $rule = TaxonomyRule::query()->create([
                'source' => $row->source,
                'make' => $row->make,
                'unknown_tail' => $row->unknown_tail,
                'action' => $action,
                'action_value' => $value !== '' ? $value : null,
                'priority' => 100,
                'is_active' => true,
                'notes' => 'Bootstrap from anomaly queue #' . $row->id,
            ]);

            $row->status = 'rule_created';
            $row->rule_id = $rule->id;
            $row->save();
            $created++;
        }

        $this->line("rows_scanned={$rows->count()}, candidates={$candidates}, created={$created}, skipped_existing={$skippedExisting}");

        return self::SUCCESS;
    }
}
