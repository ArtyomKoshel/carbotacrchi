<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    public function logs(Request $request)
    {
        $baseFile = config('admin.log_file');
        $defaultLines = config('admin.log_lines', 1000);
        $maxLines = min((int) $request->query('limit', $defaultLines), 20000);
        $level    = $request->query('level', '');
        $search   = trim($request->query('search', ''));
        $source   = trim($request->query('source', ''));
        $fileIdx  = (int) $request->query('file', 0);
        $page     = max(0, (int) $request->query('page', 0));
        $appLog     = $request->query('app') === '1';
        $appFile    = trim($request->query('appfile', ''));
        $appLogPath = storage_path('logs/laravel.log');
        $appLogFiles = $this->scanAppLogFiles();
        if ($appLog && $appFile) {
            $appLogPath = storage_path('logs/' . basename($appFile));
        } elseif ($appLog && !$appFile && !empty($appLogFiles)) {
            $appLogPath = $appLogFiles[0]['path'];
        }

        $rotationFiles = [];
        if ($baseFile) {
            if (file_exists($baseFile)) {
                $rotationFiles[] = ['idx' => 0, 'path' => $baseFile, 'label' => basename($baseFile)];
            }
            for ($i = 1; $i <= 10; $i++) {
                $path = $baseFile . '.' . $i;
                if (file_exists($path)) {
                    $rotationFiles[] = ['idx' => $i, 'path' => $path, 'label' => basename($baseFile) . '.' . $i];
                } else {
                    break;
                }
            }
        }

        $jobFiles = [];
        $jobFile  = trim($request->query('job', ''));
        if ($baseFile) {
            $jobDir = dirname($baseFile) . '/jobs';
            if (is_dir($jobDir)) {
                $found = glob($jobDir . '/job-*.log');
                if ($found) {
                    usort($found, fn($a, $b) => filemtime($b) - filemtime($a));
                    foreach ($found as $jf) {
                        $jobFiles[] = ['path' => $jf, 'label' => basename($jf), 'size' => filesize($jf), 'mtime' => filemtime($jf)];
                    }
                }
            }
        }

        $logFile = $baseFile;
        if ($appLog && file_exists($appLogPath)) {
            $logFile = $appLogPath;
        } elseif ($jobFile) {
            $logFile = dirname($baseFile) . '/jobs/' . basename($jobFile);
            if (!file_exists($logFile)) {
                $logFile = $baseFile;
            }
        } elseif ($fileIdx > 0) {
            $found = array_filter($rotationFiles, fn($f) => $f['idx'] === $fileIdx);
            if ($found) {
                $logFile = reset($found)['path'];
            }
        }

        $lines      = [];
        $error      = null;
        $totalLines = 0;
        $totalPages = 1;
        $fileSize   = 0;

        if (!$logFile || !file_exists($logFile)) {
            $error = "Log file not found: {$logFile}";
        } else {
            $needed = ($page + 1) * $maxLines;
            [$filtered, $scannedBytes, $fileSize] = $this->readFilteredTail($logFile, $needed, $level, $search, $source);

            $matchedCount = count($filtered);
            if ($scannedBytes >= $fileSize) {
                $totalLines = $matchedCount;
            } else {
                $totalLines = max($matchedCount, (int) ($matchedCount * ($fileSize / max(1, $scannedBytes))));
            }
            $totalPages = max(1, (int) ceil($totalLines / $maxLines));
            $page       = min($page, max(0, (int) ceil($matchedCount / $maxLines) - 1));
            $lines      = array_slice($filtered, $page * $maxLines, $maxLines);
        }

        return view('admin.logs', compact('lines', 'error', 'level', 'search', 'source', 'fileIdx', 'rotationFiles', 'maxLines', 'page', 'totalLines', 'totalPages', 'jobFiles', 'jobFile', 'fileSize', 'appLog', 'appLogPath', 'appLogFiles', 'appFile'));
    }

    public function logsTail(Request $request)
    {
        $baseFile = config('admin.log_file');
        if (!$baseFile) {
            return response()->json(['lines' => [], 'error' => 'Log file path not configured'], 400);
        }

        $level     = $request->query('level', '');
        $search    = trim($request->query('search', ''));
        $source    = trim($request->query('source', ''));
        $fileIdx   = (int) $request->query('file', 0);
        $jobFile   = trim($request->query('job', ''));
        $appLog     = $request->query('app') === '1';
        $appFile    = trim($request->query('appfile', ''));
        $appLogPath = storage_path('logs/laravel.log');
        if ($appLog && $appFile) {
            $appLogPath = storage_path('logs/' . basename($appFile));
        } elseif ($appLog) {
            $appLogFiles = $this->scanAppLogFiles();
            if (!empty($appLogFiles)) {
                $appLogPath = $appLogFiles[0]['path'];
            }
        }
        $sinceByte = max(0, (int) $request->query('since_byte', 0));
        $limit     = min(max((int) $request->query('limit', 1500), 1), 5000);

        $logFile = $baseFile;
        if ($appLog && file_exists($appLogPath)) {
            $logFile = $appLogPath;
        } elseif ($jobFile) {
            $candidate = dirname($baseFile) . '/jobs/' . basename($jobFile);
            if (file_exists($candidate)) {
                $logFile = $candidate;
            }
        } elseif ($fileIdx > 0) {
            $candidate = $baseFile . '.' . $fileIdx;
            if (file_exists($candidate)) {
                $logFile = $candidate;
            }
        }

        if (!file_exists($logFile)) {
            return response()->json(['lines' => [], 'error' => "Log file not found: {$logFile}"], 404);
        }

        $fileSize = filesize($logFile) ?: 0;
        if ($sinceByte > $fileSize) {
            $sinceByte = 0;
        }

        $fp = @fopen($logFile, 'rb');
        if (!$fp) {
            return response()->json(['lines' => [], 'error' => 'Cannot read log file'], 500);
        }

        if ($sinceByte > 0) {
            fseek($fp, $sinceByte);
        }

        $lines = [];
        while (!feof($fp) && count($lines) < $limit) {
            $line = fgets($fp);
            if ($line === false) {
                break;
            }
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
            if ($source && stripos($line, $source) === false) {
                continue;
            }
            $lines[] = $line;
        }

        $nextByte = ftell($fp);
        fclose($fp);

        return response()->json([
            'lines'      => $lines,
            'next_byte'  => is_int($nextByte) ? $nextByte : $fileSize,
            'file_size'  => $fileSize,
            'file'       => basename($logFile),
        ]);
    }

    public function logsClear(Request $request)
    {
        $logFile = config('admin.log_file');
        if (!$logFile) {
            return redirect()->route('admin.logs')->with('error', 'Log file path not configured');
        }
        try {
            $cleared = 0;
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
                $cleared++;
            }
            for ($i = 1; $i <= 10; $i++) {
                $rotated = $logFile . '.' . $i;
                if (file_exists($rotated)) {
                    unlink($rotated);
                    $cleared++;
                } else {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return redirect()->route('admin.logs')->with('error', 'Clear failed: ' . $e->getMessage());
        }
        return redirect()->route('admin.logs')->with('success', "Log cleared ({$cleared} file(s) removed)");
    }

    public function logsClearJobs(Request $request)
    {
        $baseFile = config('admin.log_file');
        if (!$baseFile) {
            return redirect()->route('admin.logs')->with('error', 'Log file path not configured');
        }
        $jobDir = dirname($baseFile) . '/jobs';
        if (!is_dir($jobDir)) {
            return redirect()->route('admin.logs')->with('error', 'No job logs directory');
        }
        try {
            $files = glob($jobDir . '/job-*.log*');
            $deleted = 0;
            foreach ($files as $f) {
                if (unlink($f)) {
                    $deleted++;
                }
            }
        } catch (\Throwable $e) {
            return redirect()->route('admin.logs')->with('error', 'Clear failed: ' . $e->getMessage());
        }
        return redirect()->route('admin.logs')->with('success', "Deleted {$deleted} job log file(s)");
    }

    public function logsDownload(Request $request)
    {
        $baseFile = config('admin.log_file');
        if (!$baseFile || !file_exists($baseFile)) {
            abort(404, 'Log file not found');
        }

        $fileIdx = (int) $request->query('file', 0);
        $jobFile = trim($request->query('job', ''));
        $appLog  = $request->query('app') === '1';
        $appLogPath = storage_path('logs/laravel.log');
        $level  = $request->query('level', '');
        $search = trim($request->query('search', ''));
        $source = trim($request->query('source', ''));

        $logFile = $baseFile;
        if ($appLog && file_exists($appLogPath)) {
            $logFile = $appLogPath;
        } elseif ($jobFile) {
            $candidate = dirname($baseFile) . '/jobs/' . basename($jobFile);
            if (file_exists($candidate)) {
                $logFile = $candidate;
            }
        } elseif ($fileIdx > 0) {
            $candidate = $baseFile . '.' . $fileIdx;
            if (file_exists($candidate)) {
                $logFile = $candidate;
            }
        }

        if (!file_exists($logFile)) {
            abort(404, 'Selected log file not found');
        }

        if (!$level && !$search && !$source) {
            return response()->download($logFile, basename($logFile));
        }

        $suffix = implode('-', array_filter([$level, $source, $search ? 'filtered' : '']));
        $filename = pathinfo($logFile, PATHINFO_FILENAME) . ($suffix ? "-{$suffix}" : '') . '-' . now()->format('Ymd-His') . '.log';

        return response()->streamDownload(function () use ($logFile, $level, $search, $source) {
            $fp = fopen($logFile, 'rb');
            if (!$fp) {
                return;
            }
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) {
                    break;
                }
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
                if ($source && stripos($line, $source) === false) {
                    continue;
                }
                echo $line . PHP_EOL;
            }
            fclose($fp);
        }, $filename, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Scan storage/logs/ for all laravel*.log files, sorted newest-first.
     * Returns array of ['path', 'label', 'size', 'mtime'].
     */
    private function scanAppLogFiles(): array
    {
        $dir   = storage_path('logs');
        $found = array_merge(
            glob($dir . '/laravel.log')   ?: [],
            glob($dir . '/laravel-*.log') ?: [],
        );
        if (!$found) {
            return [];
        }

        usort($found, fn ($a, $b) => filemtime($b) - filemtime($a));

        return array_map(fn ($path) => [
            'path'  => $path,
            'label' => basename($path),
            'size'  => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
        ], $found);
    }

    /**
     * Read from end of file in chunks, filtering on the fly.
     * Returns [$matchedLines (newest-first), $bytesScanned, $fileSize].
     */
    private function readFilteredTail(string $path, int $needed, string $level, string $search, string $source): array
    {
        $fp = @fopen($path, 'r');
        if (!$fp) {
            return [[], 0, 0];
        }

        $fileSize = filesize($path);
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $scanned = 0;
        $results = [];
        $remainder = '';
        $chunkSize = 65536;

        while ($pos > 0 && count($results) < $needed) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $chunk = fread($fp, $read);
            $scanned += $read;

            $block = $chunk . $remainder;
            $lines = explode("\n", $block);
            $remainder = array_shift($lines);

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                if ($line === '') {
                    continue;
                }
                if ($level  && !str_contains($line, "[{$level}]")) {
                    continue;
                }
                if ($search && !str_contains($line, $search)) {
                    continue;
                }
                if ($source && !str_contains($line, $source)) {
                    continue;
                }
                $results[] = $line;
                if (count($results) >= $needed) {
                    break;
                }
            }
        }

        if ($remainder !== '' && count($results) < $needed) {
            $line = $remainder;
            $pass = true;
            if ($level  && !str_contains($line, "[{$level}]")) {
                $pass = false;
            }
            if ($search && !str_contains($line, $search)) {
                $pass = false;
            }
            if ($source && !str_contains($line, $source)) {
                $pass = false;
            }
            if ($pass) {
                $results[] = $line;
            }
            $scanned = $fileSize;
        }

        fclose($fp);
        return [$results, $scanned, $fileSize];
    }
}
