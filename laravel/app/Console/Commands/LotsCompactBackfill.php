<?php

namespace App\Console\Commands;

use App\Support\LotPhotos;
use App\Support\OptionBits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off fill of the compact lots columns that replace the pivot tables:
 *   options  — lots.options_mask from lots.options JSON (App\Support\OptionBits)
 *   photos   — lots.photo_* from lot_photos (App\Support\LotPhotos)
 *
 * Walks `lots` in primary-key chunks, only touches rows still NULL (so it is
 * resumable and never overwrites what the parser has written since), and is
 * safe to run while the parser is upserting.
 *
 *   php artisan lots:compact-backfill                 # both
 *   php artisan lots:compact-backfill --only=photos
 *   php artisan lots:compact-backfill --verify        # compare with the pivots, no writes
 */
class LotsCompactBackfill extends Command
{
    protected $signature = 'lots:compact-backfill
        {--only= : options|photos}
        {--chunk=2000 : Lots per chunk}
        {--verify : Compare the compact columns with lot_options / lot_photos instead of writing}';

    protected $description = 'Fill lots.options_mask and lots.photo_* from the old pivot tables';

    public function handle(): int
    {
        $only  = $this->option('only');
        $chunk = max(100, (int) $this->option('chunk'));
        if ($only !== null && !in_array($only, ['options', 'photos'], true)) {
            $this->error('--only must be options or photos');
            return self::FAILURE;
        }

        foreach (['options', 'photos'] as $part) {
            if ($only !== null && $only !== $part) {
                continue;
            }
            if ($part === 'photos' && !DB::getSchemaBuilder()->hasTable('lot_photos')) {
                $this->warn('lot_photos is gone — nothing to backfill photos from.');
                continue;
            }
            if ($part === 'options' && $this->option('verify') && !DB::getSchemaBuilder()->hasTable('lot_options')) {
                $this->warn('lot_options is gone — nothing to verify options against.');
                continue;
            }
            $t0 = microtime(true);
            $n  = $this->option('verify') ? $this->verify($part, $chunk) : $this->backfill($part, $chunk);
            $this->info(sprintf('%s: %s lots in %.1fs', $part, number_format($n), microtime(true) - $t0));
        }

        return self::SUCCESS;
    }

    private function backfill(string $part, int $chunk): int
    {
        $done = 0;
        foreach ($this->ranges($chunk) as [$from, $to]) {
            $done += $part === 'options' ? $this->fillOptions($from, $to) : $this->fillPhotos($from, $to);
            $this->output->write("\r  {$part}: " . number_format($done) . " lots updated (at id {$to})");
        }
        $this->newLine();
        return $done;
    }

    /** Pure SQL: mask = BIT_OR of the catalog bits of the codes in the JSON list. */
    private function fillOptions(string $from, ?string $to): int
    {
        return DB::update(
            "UPDATE lots l SET l.options_mask = (
                 SELECT COALESCE(BIT_OR(1 << c.bit), 0)
                 FROM JSON_TABLE(l.options, '$[*]' COLUMNS (code VARCHAR(20) PATH '$')) jt
                 JOIN encar_option_catalog c ON c.code = jt.code AND c.bit < " . OptionBits::MASK_BITS . "
             )
             WHERE l.id > ?" . ($to !== null ? ' AND l.id <= ?' : '') . "
               AND l.options_mask IS NULL AND JSON_LENGTH(l.options) > 0",
            $to !== null ? [$from, $to] : [$from],
        );
    }

    private function fillPhotos(string $from, ?string $to): int
    {
        $rows = DB::select(
            'SELECT p.lot_id, p.url FROM lots l JOIN lot_photos p ON p.lot_id = l.id
             WHERE l.id > ?' . ($to !== null ? ' AND l.id <= ?' : '') . ' AND l.photo_codes IS NULL
             ORDER BY p.lot_id, p.position',
            $to !== null ? [$from, $to] : [$from],
        );
        if (!$rows) {
            return 0;
        }

        $byLot = [];
        foreach ($rows as $r) {
            $byLot[$r->lot_id][] = $r->url;
        }

        $values = $bindings = [];
        foreach ($byLot as $lotId => $urls) {
            $cols       = LotPhotos::encode($urls);
            $values[]   = 'ROW(?, ?, ?, ?, ?, ?)';
            array_push($bindings, $lotId, $cols['photo_dir'], $cols['photo_base_id'],
                $cols['photo_codes'], $cols['photo_ext_upper'], $cols['photo_urls']);
        }

        // `photo_codes IS NULL` again: the parser may have written fresher
        // photos for a lot between the SELECT and this UPDATE.
        return DB::update(
            'UPDATE lots l JOIN (VALUES ' . implode(', ', $values) . ') AS v (id, d, b, c, u, j) ON v.id = l.id
             SET l.photo_dir = v.d, l.photo_base_id = v.b, l.photo_ext_upper = v.u,
                 l.photo_urls = v.j, l.photo_codes = v.c
             WHERE l.photo_codes IS NULL',
            $bindings,
        );
    }

    /** Count lots whose compact columns disagree with the pivot table. */
    private function verify(string $part, int $chunk): int
    {
        $checked = $bad = 0;
        foreach ($this->ranges($chunk) as [$from, $to]) {
            $range = 'l.id > ?' . ($to !== null ? ' AND l.id <= ?' : '');
            $args  = $to !== null ? [$from, $to] : [$from];

            if ($part === 'options') {
                $bits = OptionBits::map();
                $pivot = [];
                foreach (DB::select("SELECT o.lot_id, o.option_code FROM lot_options o JOIN lots l ON l.id = o.lot_id WHERE {$range}", $args) as $r) {
                    $pivot[$r->lot_id][] = $r->option_code;
                }
                foreach (DB::select("SELECT l.id, l.options_mask FROM lots l WHERE {$range}", $args) as $lot) {
                    $want = 0;
                    foreach ($pivot[$lot->id] ?? [] as $code) {
                        if (isset($bits[$code]) && $bits[$code] < OptionBits::MASK_BITS) {
                            $want |= 1 << $bits[$code];
                        }
                    }
                    // Unsigned decimal strings: bit 63 doesn't fit a PHP int.
                    $have = (string) ($lot->options_mask ?? '0');
                    $want = OptionBits::unsigned($want);
                    $checked++;
                    if ($have !== $want && $this->reportMismatch($bad++)) {
                        $this->warn("  options mismatch {$lot->id}: mask {$have} vs pivot {$want}");
                    }
                }
            } else {
                $pivot = [];
                foreach (DB::select("SELECT p.lot_id, p.url FROM lot_photos p JOIN lots l ON l.id = p.lot_id WHERE {$range} ORDER BY p.lot_id, p.position", $args) as $r) {
                    $pivot[$r->lot_id][] = $r->url;
                }
                $cols = implode(', ', array_map(fn ($c) => "l.{$c}", LotPhotos::COLUMNS));
                foreach (DB::select("SELECT l.id, {$cols} FROM lots l WHERE {$range}", $args) as $lot) {
                    $checked++;
                    if (LotPhotos::urls($lot) !== ($pivot[$lot->id] ?? []) && $this->reportMismatch($bad++)) {
                        $this->warn("  photos mismatch {$lot->id}");
                    }
                }
            }
        }
        $this->info("  {$part}: {$bad} mismatches in " . number_format($checked) . ' lots');
        return $checked;
    }

    private function reportMismatch(int $seen): bool
    {
        return $seen < 20;
    }

    /**
     * (from, to] primary-key ranges of ~$chunk lots; the last one has to = null.
     *
     * @return \Generator<array{0: string, 1: ?string}>
     */
    private function ranges(int $chunk): \Generator
    {
        $from = '';
        while (true) {
            $to = DB::table('lots')->where('id', '>', $from)->orderBy('id')->offset($chunk - 1)->value('id');
            yield [$from, $to];
            if ($to === null) {
                return;
            }
            $from = $to;
        }
    }
}
