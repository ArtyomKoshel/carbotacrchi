<?php

namespace App\Services\Notifications;

use App\Ingest\IngestRunStats;
use App\Models\NotificationEvent;
use App\Models\ParseJob;

/** Short Russian Telegram texts (HTML parse mode) about parse_jobs. */
class JobReport
{
    public static function started(ParseJob $job): string
    {
        $p       = $job->progress ?? [];
        // The collector keeps a checkpoint in every progress and says a resume explicitly;
        // the old parser wrote a checkpoint only when it resumed.
        $resumed = isset($p['collector'])
            ? !empty($p['resumed']) || ($p['status'] ?? '') === 'resuming'
            : !empty($p['checkpoint']) || ($p['status'] ?? '') === 'resuming';

        $lines = ['▶️ <b>Парсинг '.self::title($job).($resumed ? ' возобновлён' : ' запущен').'</b>'];
        $lines[] = 'Запустил: '.e($job->triggered_by ?? '—').self::filtersLine($job);
        if (($p['api_total'] ?? 0) > 0) {
            $lines[] = self::lotsLine($job);
        }
        return implode("\n", $lines);
    }

    public static function progress(ParseJob $job): string
    {
        $s     = self::stats($job);
        $phase = (string) ($job->progress['phase'] ?? '');
        $head  = '⏳ <b>Парсинг '.self::title($job).'</b>';
        if ($s['pct'] !== null) {
            $head .= ' — '.self::pct($s['pct']);
        }

        $lines = [$head, self::lotsLine($job).($phase !== '' ? ' · фаза '.e($phase) : '')];

        if ($phase !== '' && $phase !== 'search' && isset($job->progress['phase_progress'])) {
            $lines[] = 'Фаза '.e($phase).': '.self::pct((float) $job->progress['phase_progress'] * 100);
        }
        $rate = self::rateLine($s, $phase === 'search' || $phase === '');
        if ($rate !== '') {
            $lines[] = $rate;
        }
        $lines[] = self::countsLine($s);
        $lines[] = self::footerLine($s);

        return implode("\n", array_filter($lines));
    }

    public static function finished(ParseJob $job): string
    {
        $s = self::stats($job);
        $lines = [
            '✅ <b>Парсинг '.self::title($job).' завершён</b>',
            self::lotsLine($job),
            self::rateLine($s, false),
            self::countsLine($s, true),
            self::footerLine($s),
        ];
        return implode("\n", array_filter($lines));
    }

    public static function failed(ParseJob $job): string
    {
        $error = (string) ($job->result['error'] ?? 'неизвестная ошибка');
        $s = self::stats($job);
        return implode("\n", array_filter([
            '❌ <b>Парсинг '.self::title($job).': ошибка</b>',
            '<code>'.e(mb_strimwidth($error, 0, 400, '…')).'</code>',
            $s['done'] > 0 ? self::lotsLine($job) : '',
        ]));
    }

    public static function cancelled(ParseJob $job): string
    {
        return '⛔ <b>Парсинг '.self::title($job).' отменён</b>';
    }

    public static function interrupted(ParseJob $job): string
    {
        $reason = (string) ($job->progress['interrupted_reason'] ?? '');
        return implode("\n", array_filter([
            '⚠️ <b>Парсинг '.self::title($job).' прерван</b>'.($reason !== '' ? ': '.e($reason) : ''),
            'Продолжится с чекпоинта, когда воркер подхватит задачу.',
        ]));
    }

    public static function resumed(ParseJob $job): string
    {
        return '✅ <b>Парсинг '.self::title($job).' продолжается</b> после прерывания';
    }

    public static function stalled(ParseJob $job, int $idleMinutes): string
    {
        $phase = (string) ($job->progress['phase'] ?? '');
        return implode("\n", array_filter([
            '⚠️ <b>Парсинг '.self::title($job).": нет записи {$idleMinutes} мин</b>",
            'Последнее обновление: '.$job->updated_at?->format('d.m H:i').($phase !== '' ? ' · фаза '.e($phase) : ''),
            self::lotsLine($job),
        ]));
    }

    public static function unstalled(ParseJob $job, NotificationEvent $event): string
    {
        $pause = $event->opened_at ? ' (пауза ~'.self::duration(max(0, $event->opened_at->diffInSeconds(now()))).')' : '';
        return '✅ <b>Парсинг '.self::title($job).': запись возобновилась</b>'.$pause;
    }

    /** @return array{done: int, total: int, pct: ?float, elapsed: float, new: int, updated: int, written: ?int, errors: int, filtered: ?int, stale: ?int, proxy_bytes: int, buffered: ?int} */
    public static function stats(ParseJob $job): array
    {
        $p = $job->progress ?? [];
        $r = $job->result ?? [];

        $done  = (int) ($r['total'] ?? $p['found_total'] ?? $p['total'] ?? 0);
        $total = (int) ($r['api_total'] ?? $p['api_total'] ?? 0);
        // A collector run: new / updated / filtered are what the core applied (ingest_run_stats).
        $run = isset($p['collector']) ? IngestRunStats::of((int) $job->id) : null;

        return [
            'done'        => $done,
            'total'       => $total,
            'pct'         => $total > 0 ? min(100.0, $done / $total * 100) : null,
            'elapsed'     => (float) ($r['elapsed_s'] ?? $p['elapsed_s'] ?? 0),
            'new'         => $run ? $run['inserted'] : (int) ($r['new'] ?? $p['new'] ?? 0),
            'updated'     => $run ? $run['updated'] : (int) ($r['updated'] ?? $p['updated'] ?? 0),
            'written'     => $run ? $run['inserted'] + $run['updated'] + $run['unchanged'] : (isset($r['lots_written']) ? (int) $r['lots_written'] : null),
            'errors'      => array_sum(self::errorTypes($job)),
            'filtered'    => $run ? $run['filtered'] : (isset($r['filtered']) ? (int) $r['filtered'] : null),
            'stale'       => isset($r['stale']) ? (int) $r['stale'] : null,
            'proxy_bytes' => (int) ($r['proxy_bytes'] ?? $p['proxy_bytes'] ?? 0),
            'buffered'    => self::buffered($job, $done, $run),
        ];
    }

    /**
     * A che168 run: cars the collector put in the ingest buffer that the core has not applied yet
     * (ingest:consume runs every minute, so the last ones land just after the run ends). Null for
     * Encar, whose run total counts lots it never sends (PRE rules).
     *
     * @param  array{inserted: int, updated: int, unchanged: int, filtered: int, rejected: int}|null  $run
     */
    private static function buffered(ParseJob $job, int $sent, ?array $run): ?int
    {
        $engine = $job->result['engine'] ?? $job->progress['checkpoint']['engine'] ?? null;
        if ($engine !== 'che168') {
            return null;
        }
        $applied = $run === null ? 0 : $run['inserted'] + $run['updated'] + $run['unchanged'] + $run['filtered'] + $run['rejected'];

        return max(0, $sent - $applied);
    }

    /**
     * A collector run's errors by kind: search pages (the run's own "errors") plus the cards and
     * histories that failed to load, which it reports apart. Read from the result once the run is
     * over, from the checkpoint while it goes. An old parser run has only its "errors".
     *
     * @return array<string, int> label => count, zeros dropped
     */
    public static function errorTypes(ParseJob $job): array
    {
        $p = $job->progress ?? [];
        $r = $job->result ?? [];
        $report = $r ?: (array) ($p['checkpoint']['report'] ?? []);

        return array_filter([
            'Страницы поиска'  => (int) ($r['errors'] ?? $p['errors'] ?? 0),
            'Карточки лотов'   => (int) ($report['detail_failed'] ?? 0),
            'История лотов'    => (int) ($report['history_failed'] ?? 0),
        ]);
    }

    /** @return list<string> the collector's last error lines: the result's, or the checkpoint's while it runs */
    public static function errorLog(ParseJob $job): array
    {
        $r = $job->result ?? [];

        return array_values((array) ($r['error_log'] ?? ($job->progress['checkpoint']['error_log'] ?? [])));
    }

    public static function duration(float $seconds): string
    {
        $m = (int) round($seconds / 60);
        if ($m < 1) {
            return ((int) $seconds).' с';
        }
        if ($m < 60) {
            return "{$m} мин";
        }
        $h = intdiv($m, 60);
        $m %= 60;
        return $h >= 48 ? round($h / 24, 1).' дн' : ($m ? "{$h} ч {$m} мин" : "{$h} ч");
    }

    public static function num(int|float $n): string
    {
        return number_format($n, 0, '.', ' ');
    }

    private static function title(ParseJob $job): string
    {
        return '#'.$job->id.' · '.e($job->source);
    }

    private static function filtersLine(ParseJob $job): string
    {
        // `engine` only says which parser runs the job (CollectorMode), it filters nothing.
        $f = array_filter(
            array_diff_key((array) ($job->filters ?? []), ['engine' => true]),
            fn ($v) => $v !== null && $v !== '' && $v !== [],
        );
        if (!$f) {
            return '';
        }
        $parts = [];
        foreach ($f as $k => $v) {
            $parts[] = e($k.'='.(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
        }
        return ' · фильтр: '.implode(', ', $parts);
    }

    private static function lotsLine(ParseJob $job): string
    {
        $s = self::stats($job);
        return 'Лоты: '.self::num($s['done']).' / '.($s['total'] > 0 ? self::num($s['total']) : '?');
    }

    private static function rateLine(array $s, bool $withEta): string
    {
        if ($s['elapsed'] <= 0) {
            return '';
        }
        $perMin = $s['done'] / ($s['elapsed'] / 60);
        $line   = 'Время: '.self::duration($s['elapsed']);
        if ($s['done'] > 0) {
            $line .= ' · '.self::num($perMin).' лот/мин';
        }
        if ($withEta && $perMin > 0 && $s['total'] > $s['done']) {
            $line .= ' · ETA ~'.self::duration(($s['total'] - $s['done']) / $perMin * 60);
        }
        return $line;
    }

    private static function countsLine(array $s, bool $final = false): string
    {
        $parts = ['новые '.self::num($s['new']), 'обновлено '.self::num($s['updated'])];
        if ($final || $s['filtered'] !== null) {
            $parts[] = 'отфильтровано '.self::num($s['filtered'] ?? 0);
        }
        if ($final || $s['stale'] !== null) {
            $parts[] = 'устарело '.self::num($s['stale'] ?? 0);
        }
        return self::capitalize(implode(' · ', $parts));
    }

    private static function footerLine(array $s): string
    {
        $parts = ['ошибки '.self::num($s['errors'])];
        if ($s['proxy_bytes'] > 0) {
            $parts[] = 'прокси '.number_format($s['proxy_bytes'] / 1024 ** 3, 2, '.', '').' ГБ';
        }
        return ($s['errors'] > 0 ? '⚠️ ' : '').self::capitalize(implode(' · ', $parts));
    }

    private static function capitalize(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private static function pct(float $pct): string
    {
        return ($pct >= 10 ? (string) round($pct) : number_format($pct, 1, '.', '')).'%';
    }
}
