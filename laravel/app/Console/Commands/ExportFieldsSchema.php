<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Export Python parser field registry to storage/app/fields.json.
 *
 * Run once after every parser deploy, or schedule:
 *   $schedule->command('parser:export-fields')->hourly();
 */
class ExportFieldsSchema extends Command
{
    protected $signature = 'parser:export-fields';
    protected $description = 'Export Python CarLot field registry AND field-mappings catalogue to storage/app/*.json';

    public function handle(): int
    {
        $parserDir = env('PARSER_DIR', base_path('../parser'));
        $python    = env('PYTHON_BIN', 'python');

        if (!is_dir($parserDir)) {
            $this->error("Parser directory not found: {$parserDir}");
            $this->line('Set PARSER_DIR env var to override.');
            return self::FAILURE;
        }

        $jobs = [
            [
                'label'     => 'field registry',
                'module'    => 'fields.schema',
                'cacheKey'  => 'parser:fields:schema',
                'file'      => 'fields.json',
                'rootKey'   => 'fields',
            ],
            [
                'label'     => 'field mappings catalogue',
                'module'    => 'parsers._shared.field_mappings',
                'extraArgs' => ['--json'],
                'cacheKey'  => 'parser:field-mappings:schema',
                'file'      => 'field_mappings.json',
                'rootKey'   => 'mappings',
            ],
        ];

        $failures = 0;
        foreach ($jobs as $job) {
            $cmd = array_merge([$python, '-m', $job['module']], $job['extraArgs'] ?? []);
            $this->info("Exporting {$job['label']} ({$job['module']}) ...");

            $result = Process::path($parserDir)->timeout(10)->run($cmd);
            if (!$result->successful()) {
                $this->error("  python exited with {$result->exitCode()}");
                $this->line($result->errorOutput());
                $failures++;
                continue;
            }

            $json = trim($result->output());
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded[$job['rootKey']])) {
                $this->error("  Python output is not a valid {$job['label']} JSON");
                $failures++;
                continue;
            }

            $target = storage_path('app/' . $job['file']);
            if (@file_put_contents($target, $json) === false) {
                $this->error("  Cannot write {$target}");
                $failures++;
                continue;
            }

            Cache::forget($job['cacheKey']);
            $count = count($decoded[$job['rootKey']]);
            $this->info("  wrote {$target} — {$count} entries");
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
