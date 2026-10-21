<?php

namespace App\Support;

use App\Models\ParseJob;
use App\Services\Notifications\JobReport;

/** How parse_jobs read in the panel. */
class JobFormat
{
    public const STATUS_COLORS = [
        'pending'     => 'gray',
        'running'     => 'info',
        'done'        => 'success',
        'error'       => 'danger',
        'cancelled'   => 'gray',
        'interrupted' => 'warning',
    ];

    /** Parser phases (ProgressUpdate.phase and result.phases[].name) */
    public const PHASES = [
        'search'  => 'Поиск лотов',
        'enrich'  => 'История и осмотр',
        'inspect' => 'Осмотр',
        'delist'  => 'Снятие пропавших',
        'done'    => 'Готово',
    ];

    public static function status(?string $status): string
    {
        return AdminUiLabels::status($status);
    }

    public static function statusColor(?string $status): string
    {
        return self::STATUS_COLORS[$status] ?? 'gray';
    }

    /** «Полный», «Тест: 5 стр.», «Марка: BMW», «Перепарсинг: 3 лота» */
    public static function kind(ParseJob $job): string
    {
        if ($job->type === 'reparse') {
            $n = count((array) $job->target_lot_ids);
            return "Перепарсинг: {$n} " . self::plural($n, 'лот', 'лота', 'лотов');
        }
        $f = (array) ($job->filters ?? []);
        $parts = [];
        if (!empty($f['maker'])) {
            $parts[] = 'марка ' . $f['maker'];
        }
        if (!empty($f['max_pages'])) {
            $parts[] = $f['max_pages'] . ' стр.';
        }

        return $parts ? 'Частичный: ' . implode(', ', $parts) : 'Полный';
    }

    public static function phase(?string $phase): string
    {
        return self::PHASES[$phase] ?? ($phase ? AdminUiLabels::phase($phase) : '—');
    }

    public static function duration(?float $seconds): string
    {
        return $seconds && $seconds > 0 ? JobReport::duration($seconds) : '—';
    }

    public static function triggeredBy(?string $by): string
    {
        return match (true) {
            $by === null, $by === ''       => '—',
            $by === 'scheduler'            => 'по расписанию',
            $by === 'admin'                => 'из админки',
            str_starts_with($by, 'admin:') => substr($by, 6),
            default                        => $by,
        };
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };
    }
}
