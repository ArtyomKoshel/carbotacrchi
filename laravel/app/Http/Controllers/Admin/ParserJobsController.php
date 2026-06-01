<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParseJob;
use App\Models\ParserSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class ParserJobsController extends Controller
{
    public function lots(Request $request)
    {
        $q = trim($request->query('q', ''));
        $lots = collect();

        if ($q !== '') {
            $lots = DB::table('lots')
                ->where('id', 'like', "%{$q}%")
                ->orWhere('plate_number', 'like', "%{$q}%")
                ->orWhere('vin', 'like', "%{$q}%")
                ->orderByDesc('parsed_at')
                ->limit(30)
                ->get();
        }

        $recent = ParseJob::where('type', 'reparse')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('admin.lots', compact('lots', 'q', 'recent'));
    }

    public function reparseLot(Request $request, string $lotId)
    {
        $lot = DB::table('lots')->where('id', $lotId)->first(['id', 'source']);
        if (!$lot) {
            return redirect()->route('admin.lots')
                ->withErrors(['lot_id' => "Lot {$lotId} not found"]);
        }

        $source = $lot->source ?? null;
        if (!$source && str_contains($lotId, '_')) {
            $source = explode('_', $lotId, 2)[0];
        }
        $source = $source ?: 'encar';

        $pending = ParseJob::where('type', 'reparse')
            ->whereIn('status', ['pending', 'running', 'interrupted'])
            ->whereJsonContains('target_lot_ids', $lotId)
            ->exists();

        if (!$pending) {
            ParseJob::create([
                'source' => $source,
                'type' => 'reparse',
                'status' => 'pending',
                'target_lot_ids' => [$lotId],
                'triggered_by' => 'admin',
            ]);
        }

        return redirect()->route('admin.lots', ['q' => $lotId])
            ->with('success', "Re-parse queued for {$lotId}");
    }

    public function reparseStatus(string $id)
    {
        $job = ParseJob::find($id);
        if (!$job) {
            abort(404);
        }

        return response()->json([
            'status' => $job->status,
            'result' => $job->result['error'] ?? ($job->status === 'done' ? 'OK' : null),
            'updated_at' => $job->updated_at?->toISOString(),
        ]);
    }

    public function jobs(Request $request)
    {
        $jobs = ParseJob::orderByDesc('created_at')->paginate(30);
        $sources = array_values(config('auction.sources', ['encar']));

        return view('admin.jobs', compact('jobs', 'sources'));
    }

    public function launchJob(Request $request)
    {
        $source = $request->input('source');
        $filters = array_filter([
            'max_pages' => (int) $request->input('max_pages', 0) ?: null,
            'maker' => $request->input('maker') ?: null,
        ]);

        $job = ParseJob::create([
            'source' => $source,
            'status' => 'pending',
            'filters' => $filters ?: null,
            'triggered_by' => 'admin',
        ]);

        return redirect()->route('admin.jobs')
            ->with('success', "Job #{$job->id} queued for {$source}");
    }

    public function cancelJob(Request $request, int $id)
    {
        ParseJob::where('id', $id)->whereIn('status', ['pending', 'running', 'interrupted'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        return redirect()->route('admin.jobs')
            ->with('success', "Job #{$id} cancelled");
    }

    public function jobProgress(int $id)
    {
        $job = ParseJob::findOrFail($id);
        session()->save();

        return response()->stream(function () use ($id, $job) {
            set_time_limit(0);

            $send = function (array $payload) {
                echo 'data: ' . json_encode($payload) . "\n\n";
                ob_flush();
                flush();
            };

            $waitDeadline = time() + 120;
            while (time() < $waitDeadline) {
                $job = ParseJob::find($id);
                if (!$job) {
                    return;
                }
                $send(['job_id' => $id, 'status' => $job->status]);
                if ($job->status !== 'pending') {
                    break;
                }
                sleep(1);
            }

            if (!$job || in_array($job->status, ['done', 'error', 'cancelled'], true)) {
                return;
            }

            $channel = "parse_progress:{$job->source}";
            $redis = Redis::connection('default')->client();
            if (defined('Redis::OPT_READ_TIMEOUT')) {
                $redis->setOption(constant('Redis::OPT_READ_TIMEOUT'), 30);
            }
            $deadline = time() + 1800;

            try {
                $redis->subscribe([$channel], function ($r, $chan, $message) use ($id, $send, &$deadline) {
                    $data = json_decode($message, true);
                    if (($data['job_id'] ?? null) != $id) {
                        return time() < $deadline ? null : false;
                    }
                    $send($data);
                    if (in_array($data['status'] ?? '', ['done', 'error', 'cancelled'], true)) {
                        return false;
                    }

                    return time() < $deadline ? null : false;
                });
            } catch (\Throwable $e) {
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function jobEvents(int $id)
    {
        $job = ParseJob::findOrFail($id);
        $since = ($job->created_at ?? now())->copy()->subSeconds(5);

        $lots = DB::table('lots')
            ->where('source', $job->source)
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'make', 'model', 'year', 'price', 'mileage', 'updated_at']);

        $changedIds = DB::table('lot_changes')
            ->where('source', $job->source)
            ->where('recorded_at', '>=', $since)
            ->pluck('lot_id')
            ->unique()
            ->toArray();

        return response()->json([
            'job_id' => $id,
            'status' => $job->status,
            'total' => $lots->count(),
            'changed' => count($changedIds),
            'lots' => $lots->map(fn ($l) => [
                'id' => $l->id,
                'title' => trim(($l->make ?? '') . ' ' . ($l->model ?? '') . ' ' . ($l->year ?? '')),
                'price' => $l->price,
                'mileage' => $l->mileage,
                'changed' => in_array($l->id, $changedIds),
            ]),
        ]);
    }

    public function jobDetail(int $id)
    {
        $job = ParseJob::findOrFail($id);
        $stat = DB::table('job_stats')->where('job_id', $id)->first();

        return view('admin.job-detail', compact('job', 'stat'));
    }

    public function jobLog(Request $request, int $id)
    {
        $job = ParseJob::findOrFail($id);
        $baseFile = config('admin.log_file');
        if (!$baseFile) {
            return response()->json(['lines' => [], 'error' => 'Log file not configured']);
        }

        $jobLogPath = dirname($baseFile) . '/jobs/job-' . $id . '.log';
        if (!file_exists($jobLogPath)) {
            return response()->json(['lines' => [], 'error' => "Job log not found: job-{$id}.log"]);
        }

        $level = $request->query('level', '');
        $search = trim($request->query('search', ''));
        $page = max(0, (int) $request->query('page', 0));
        $perPage = min((int) $request->query('limit', 500), 5000);
        $sinceByte = max(0, (int) $request->query('since_byte', 0));
        $fileSize = filesize($jobLogPath) ?: 0;

        $fp = @fopen($jobLogPath, 'rb');
        if (!$fp) {
            return response()->json(['lines' => [], 'error' => 'Cannot read log file']);
        }

        if ($sinceByte > 0 && !$level && !$search && $page === 0) {
            if ($sinceByte > $fileSize) {
                $sinceByte = 0;
            }
            fseek($fp, $sinceByte);
            $lines = [];
            while (!feof($fp) && count($lines) < $perPage) {
                $line = fgets($fp);
                if ($line === false) {
                    break;
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
            }
            $nextByte = ftell($fp);
            fclose($fp);

            return response()->json([
                'lines' => $lines,
                'total' => count($lines),
                'total_raw' => null,
                'next_raw_line' => null,
                'next_byte' => is_int($nextByte) ? $nextByte : $fileSize,
                'page' => 0,
                'total_pages' => 1,
                'per_page' => $perPage,
                'file_size' => $fileSize,
            ]);
        }

        rewind($fp);
        $totalRaw = 0;
        $totalFiltered = 0;
        $pageLines = [];
        $start = $page * $perPage;
        $end = $start + $perPage;

        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $totalRaw++;

            if ($level && !str_contains($line, "[{$level}]")) {
                continue;
            }
            if ($search && stripos($line, $search) === false) {
                continue;
            }

            if ($totalFiltered >= $start && $totalFiltered < $end) {
                $pageLines[] = $line;
            }
            $totalFiltered++;
        }
        fclose($fp);

        $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
        $page = min($page, $totalPages - 1);

        return response()->json([
            'lines' => $pageLines,
            'total' => $totalFiltered,
            'total_raw' => $totalRaw,
            'next_raw_line' => $totalRaw,
            'next_byte' => $fileSize,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'file_size' => $fileSize,
        ]);
    }

    public function schedules()
    {
        $schedules = ParserSchedule::orderBy('source')->get()->keyBy('source');
        $sources = array_values(config('auction.sources', ['encar']));

        return view('admin.schedules', compact('schedules', 'sources'));
    }

    public function updateSchedule(Request $request, string $source)
    {
        try {
            ParserSchedule::updateOrCreate(['source' => $source], [
                'enabled' => (bool) $request->input('enabled'),
                'schedule' => $request->input('schedule') ?? '',
                'interval_minutes' => (int) $request->input('interval_minutes', 60),
                'max_pages' => (int) $request->input('max_pages', 0),
                'maker_filter' => $request->input('maker_filter') ?: null,
            ]);
        } catch (\Throwable $e) {
            return response("DB error in updateSchedule: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 500)
                ->header('Content-Type', 'text/plain');
        }

        return redirect()->route('admin.schedules')
            ->with('success', "Schedule for {$source} updated.");
    }

    public function proxyBalance(): \Illuminate\Http\JsonResponse
    {
        $key = config('auction.floppydata_api_key');
        if (!$key) {
            return response()->json(['error' => 'API key not configured'], 404);
        }

        try {
            $baseUrl = rtrim(config('auction.floppy_base_url'), '/');
            $resp = Http::connectTimeout(3)->timeout(8)
                ->withHeader('X-Api-Key', $key)
                ->get("{$baseUrl}/v1/rotating/balance");
            if ($resp->successful()) {
                $body = $resp->json();
                \Illuminate\Support\Facades\Log::debug('[FloppyData] balance response', ['body' => $body]);
                return response()->json($body);
            }

            return response()->json(['error' => 'API error ' . $resp->status()], 502);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
