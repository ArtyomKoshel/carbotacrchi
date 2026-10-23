<?php

namespace App\Console\Commands;

use App\Catalog\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Encar's Korean model names → its English ones ("5시리즈" → "5 Series"), for the "names" of
 * resources/catalog/encar/{make}.json, from lots that carry both. A pair counts only where both
 * sides name the same generation ("5시리즈 (G30)" / "5 Series (G30)"): a translation left from a
 * listing's older model does not. Prints what is new; --write adds it (existing names stay).
 */
class CatalogEncarNames extends Command
{
    protected $signature = 'catalog:encar-names {--make= : One make (English), else every make of the reference} {--write : Add the new names to the files}';

    protected $description = "Collect Encar's Korean → English model names for the catalog's Encar files";

    public function handle(Reference $reference): int
    {
        $makes = $this->option('make') ? [(string) $this->option('make')] : $reference->makeNames();
        foreach ($makes as $make) {
            $path = resource_path('catalog/encar/' . strtolower(str_replace(' ', '-', $make)) . '.json');
            $file = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

            $votes = [];
            $korean = null;
            $rows = DB::table('lots')->where('source', 'encar')->where('make_en', $make)->whereNotNull('model_en')
                ->groupBy('make', 'model', 'model_en')->selectRaw('make, model, model_en, COUNT(*) n')->get();
            foreach ($rows as $r) {
                $korean ??= $r->make;
                [$kn, $kc] = self::split((string) $r->model);
                [$en, $ec] = self::split((string) $r->model_en);
                if ($kc === $ec) {
                    $votes[$kn][$en] = ($votes[$kn][$en] ?? 0) + (int) $r->n;
                }
            }

            $new = [];
            foreach ($votes as $kn => $candidates) {
                arsort($candidates);
                $en = array_key_first($candidates);
                if ($en !== $kn && ! isset($file['names'][$kn])) {
                    $new[$kn] = $en;
                }
            }
            $this->line(sprintf('<info>%s</info> (%s): %d names, %d new', $make, $korean ?? $file['korean'] ?? '?', count($file['names'] ?? []), count($new)));
            foreach ($new as $kn => $en) {
                $known = $reference->model($make, $en) ? '' : '  <comment>(not a reference model: add it, or an exception under "models")</comment>';
                $this->line("  {$kn} → {$en}{$known}");
            }
            if ($this->option('write') && ($new || ! isset($file['korean']))) {
                $file['korean'] ??= $korean;
                $file['names'] = ($file['names'] ?? []) + $new;
                ksort($file['names']);
                file_put_contents($path, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
        return self::SUCCESS;
    }

    /** The same split as App\Catalog\Encar\EncarMatcher: [name, chassis code, "#2", or null]. */
    private static function split(string $model): array
    {
        $model = trim($model);
        if (preg_match('/^(.*?)\s*\(([A-Za-z0-9]+)\)$/u', $model, $m) || preg_match('/^(.*\S)\s+([A-Z]\d{3}[a-z]?)$/u', $model, $m)) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/^(.*\S)\s+(?:(\d)세대|Gen (\d))$/u', $model, $m)) {
            return [$m[1], '#' . ($m[2] ?: $m[3])];
        }
        return [$model, null];
    }
}
