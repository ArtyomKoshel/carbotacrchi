<?php

namespace App\Services;

use App\Models\ParseJob;

/**
 * The parser's per-job log (…/jobs/job-{id}.log next to PARSER_LOG_FILE),
 * the same file the old job page reads.
 */
class ParseJobLog
{
    /** Bytes read from the end of the file; enough for a few thousand lines. */
    private const TAIL_BYTES = 512 * 1024;

    public static function path(ParseJob $job): ?string
    {
        $base = config('admin.log_file');

        return $base ? dirname($base) . '/jobs/job-' . $job->id . '.log' : null;
    }

    /**
     * Last lines of the log, optionally only one level ("ERROR", "WARNING") or
     * lines containing a text.
     *
     * @return array{lines: list<string>, error: ?string, size: int}
     */
    public static function tail(ParseJob $job, int $limit = 300, ?string $level = null, ?string $search = null): array
    {
        $path = self::path($job);
        if ($path === null) {
            return ['lines' => [], 'error' => 'Путь к логам парсера не задан (PARSER_LOG_FILE).', 'size' => 0];
        }
        if (!is_file($path)) {
            return ['lines' => [], 'error' => "Лога этой задачи нет: job-{$job->id}.log", 'size' => 0];
        }

        $size = (int) filesize($path);
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return ['lines' => [], 'error' => 'Не удалось прочитать лог.', 'size' => $size];
        }
        if ($size > self::TAIL_BYTES) {
            fseek($fp, -self::TAIL_BYTES, SEEK_END);
            fgets($fp); // drop the partial first line
        }
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            if ($level && !str_contains($line, "[{$level}]")) {
                continue;
            }
            if ($search && stripos($line, $search) === false) {
                continue;
            }
            $lines[] = $line;
        }
        fclose($fp);

        return ['lines' => array_slice($lines, -$limit), 'error' => null, 'size' => $size];
    }
}
