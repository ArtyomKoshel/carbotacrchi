<?php

namespace Database\Factories;

use App\Models\ParseJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParseJob> */
class ParseJobFactory extends Factory
{
    protected $model = ParseJob::class;

    public function definition(): array
    {
        return [
            'source'       => 'encar',
            'type'         => 'full',
            'status'       => 'pending',
            'filters'      => null,
            'triggered_by' => 'scheduler',
        ];
    }

    /** A finished full run that lots:lifecycle counts as successful. */
    public function successful(int $proxyBytes = 1_500_000_000, int $pages = 2000): static
    {
        return $this->state(fn () => [
            'status' => 'done',
            'result' => [
                'total' => 190000, 'api_total' => 195000, 'new' => 700, 'updated' => 180000, 'stale' => 600,
                'errors' => 0, 'filtered' => 9000, 'coverage_pct' => 97.5, 'lots_written' => 170000,
                'full_run' => true, 'pages' => $pages, 'proxy_bytes' => $proxyBytes, 'elapsed_s' => 40000,
                'db_count' => 133000, 'run_started_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'phases' => [['name' => 'search', 'lots_in' => 0, 'lots_out' => 190000, 'errors' => 0, 'elapsed_s' => 39000]],
                'filter_rules' => ['max_mileage' => 120],
            ],
        ]);
    }

    public function running(float $pct = 40.0): static
    {
        return $this->state([
            'status'   => 'running',
            'progress' => ['phase' => 'search', 'pct' => $pct, 'found_total' => 4000, 'api_total' => 10000, 'new' => 50, 'updated' => 3900, 'elapsed_s' => 600],
        ]);
    }
}
