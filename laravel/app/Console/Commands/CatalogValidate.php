<?php

namespace App\Console\Commands;

use App\Catalog\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks the reference catalog (read-only):
 *   files — every generation code and number once per model, years in order, an Encar exception
 *           pointing at a model and generation the reference has, a phrase not both a
 *           complectation and an edition;
 *   data  — lots placed in a generation whose years do not hold theirs (±1: a car registered
 *           the year after). Such a generation's years are wrong in the reference, or the
 *           variants placed there are — the year never places a lot, so it is a fair test.
 * Exit code 1 when a file check fails.
 */
class CatalogValidate extends Command
{
    protected $signature = 'catalog:validate {--no-data : Only the files}';

    protected $description = 'Check the reference catalog files and, against lots, its generation years';

    public function handle(Reference $reference): int
    {
        $errors = [];
        foreach ($reference->makeNames() as $makeName) {
            $make = $reference->make($makeName);
            foreach (array_intersect($make['complectations'] ?? [], $make['editions'] ?? []) as $both) {
                $errors[] = [$makeName, '—', "«{$both}» is both a complectation and an edition"];
            }
            foreach ($make['models'] as $model) {
                $codes = $numbers = [];
                foreach ($model['generations'] as $g) {
                    foreach ([$g['code'], ...($g['aliases'] ?? [])] as $c) {
                        if (isset($codes[strtoupper($c)])) {
                            $errors[] = [$makeName, $model['name'], "code {$c} twice"];
                        }
                        $codes[strtoupper($c)] = true;
                    }
                    if (isset($g['number'])) {
                        if (isset($numbers[$g['number']])) {
                            $errors[] = [$makeName, $model['name'], "generation number {$g['number']} twice"];
                        }
                        $numbers[$g['number']] = true;
                    }
                    [$from, $to] = ($g['years'] ?? []) + [null, null];
                    if ($from !== null && $to !== null && $from > $to) {
                        $errors[] = [$makeName, $model['name'], "{$g['code']}: years {$from}–{$to}"];
                    }
                }
            }
            $targets = [];
            foreach ($this->encarFiles($reference)[$makeName] ?? [] as $file) {
                $targets = [...$targets, ...array_values($file['models'] ?? []), ...array_map(fn ($b) => $b['to'], $file['badges'] ?? [])];
            }
            foreach ($targets as [$modelName, $code]) {
                $model = $reference->model($makeName, $modelName);
                if ($model === null || ($code !== null && $reference->generation($model, $code) === null)) {
                    $errors[] = [$makeName, $modelName, "Encar exception points at {$modelName} {$code}, not in the reference"];
                }
            }
        }

        if ($errors) {
            $this->error(count($errors) . ' problems in the files');
            $this->table(['Make', 'Model', 'Problem'], $errors);
        } else {
            $this->info('Files: OK');
        }

        if (! $this->option('no-data')) {
            $this->years();
        }
        return $errors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Encar files by the reference make they belong to: named after it, or naming it in "make"
     * (a second Encar make such as the imported Chevrolet, or "citroen-ds.json" for "Citroën/DS").
     *
     * @return array<string, list<array>>
     */
    private function encarFiles(Reference $reference): array
    {
        static $byMake = null;
        if ($byMake === null) {
            $byMake = [];
            foreach (glob(resource_path('catalog/encar/*.json')) as $path) {
                if (basename($path) === 'tokens.json') {
                    continue;
                }
                $file = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $ref = $reference->make($file['make'] ?? basename($path, '.json'));
                if ($ref !== null) {
                    $byMake[$ref['make']][] = $file;
                }
            }
        }
        return $byMake;
    }

    private function years(): void
    {
        $rows = DB::table('lots as l')
            ->join('ref_generations as g', 'g.id', '=', 'l.ref_generation_id')
            ->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->join('ref_makes as k', 'k.id', '=', 'm.make_id')
            ->where('l.is_active', true)
            ->groupBy('k.name', 'm.name', 'g.code', 'g.year_from', 'g.year_to')
            ->selectRaw('k.name make, m.name model, g.code, g.year_from, g.year_to, MIN(l.year) lot_min, MAX(l.year) lot_max, COUNT(*) lots,
                SUM(l.year < g.year_from - 1 OR (g.year_to IS NOT NULL AND l.year > g.year_to + 1)) outside')
            ->havingRaw('outside > 0')->orderByDesc('outside')->get();

        if ($rows->isEmpty()) {
            $this->info('Data: every placed lot is within its generation\'s years');
            return;
        }
        $this->warn('Data: lots outside their generation\'s years — the reference years, or the variants placed there, need a look');
        $this->table(['Make', 'Model', 'Generation', 'Years', 'Lots\' years', 'Lots', 'Outside'], $rows->map(fn ($r) => [
            $r->make, $r->model, $r->code, $r->year_from . '–' . ($r->year_to ?? ''), "{$r->lot_min}–{$r->lot_max}", $r->lots, $r->outside,
        ])->all());
    }
}
