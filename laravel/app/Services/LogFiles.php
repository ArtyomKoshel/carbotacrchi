<?php

namespace App\Services;

use App\Models\ParseJob;

/**
 * Log files the admin can read: the parser log (PARSER_LOG_FILE and its
 * rotations), per-job parser logs (…/jobs/job-N.log) and Laravel's own
 * (storage/logs/laravel*.log). Reading goes backwards from the end, so a
 * large file costs only the lines shown.
 */
class LogFiles
{
    private const CHUNK = 65536;

    /** @return list<array{key: string, group: string, label: string, path: string, size: int, mtime: int}> */
    public function all(): array
    {
        $files = [];
        $base = (string) config('admin.log_file', '');

        if ($base !== '') {
            foreach (array_merge([$base], array_map(fn ($i) => "{$base}.{$i}", range(1, 10))) as $i => $path) {
                if (!is_file($path)) {
                    if ($i > 0) {
                        break;
                    }
                    continue;
                }
                $files[] = $this->entry("parser:{$i}", 'Парсер', basename($path), $path);
            }
            $jobs = glob(dirname($base) . '/jobs/job-*.log') ?: [];
            usort($jobs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($jobs, 0, 50) as $path) {
                $files[] = $this->entry('job:' . basename($path), 'Задачи парсинга', basename($path), $path);
            }
        }

        $app = array_merge(glob(storage_path('logs/laravel.log')) ?: [], glob(storage_path('logs/laravel-*.log')) ?: []);
        usort($app, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach ($app as $path) {
            $files[] = $this->entry('app:' . basename($path), 'Приложение (Laravel)', basename($path), $path);
        }

        return $files;
    }

    public function find(?string $key): ?array
    {
        foreach ($this->all() as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Last matching lines, newest first. Level matches the parser's "[ERROR]"
     * and Laravel's "local.ERROR:".
     *
     * @return list<string>
     */
    public function tail(string $path, int $limit, ?string $level = null, ?string $search = null): array
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $remainder = '';
        $out = [];
        $match = function (string $line) use ($level, $search): bool {
            if ($line === '') {
                return false;
            }
            if ($level && !str_contains($line, "[{$level}]") && !str_contains($line, ".{$level}:")) {
                return false;
            }

            return !$search || stripos($line, $search) !== false;
        };

        while ($pos > 0 && count($out) < $limit) {
            $read = min(self::CHUNK, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $lines = explode("\n", fread($fp, $read) . $remainder);
            $remainder = array_shift($lines);
            for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
                $line = rtrim($lines[$i], "\r");
                if ($match($line)) {
                    $out[] = $line;
                }
            }
        }
        if ($pos === 0 && count($out) < $limit && $match(rtrim($remainder, "\r"))) {
            $out[] = rtrim($remainder, "\r");
        }
        fclose($fp);

        return $out;
    }

    /** Empty the parser log and delete its rotations (the old «Clear»). @return int files touched */
    public function clearParser(): int
    {
        $base = (string) config('admin.log_file', '');
        $n = 0;
        if ($base !== '' && is_file($base)) {
            file_put_contents($base, '');
            $n++;
        }
        for ($i = 1; $base !== '' && is_file("{$base}.{$i}"); $i++) {
            @unlink("{$base}.{$i}") && $n++;
        }

        return $n;
    }

    /** Delete job logs except those of jobs that may still write. @return array{deleted: int, kept: int} */
    public function clearJobs(): array
    {
        $base = (string) config('admin.log_file', '');
        $active = ParseJob::activeIds();
        $deleted = $kept = 0;
        foreach ($base !== '' ? (glob(dirname($base) . '/jobs/job-*.log*') ?: []) : [] as $path) {
            $id = ParseJob::idFromLogFile($path);
            if ($id !== null && isset($active[$id])) {
                $kept++;
                continue;
            }
            @unlink($path) && $deleted++;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    private function entry(string $key, string $group, string $label, string $path): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'path' => $path, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path)];
    }
}
