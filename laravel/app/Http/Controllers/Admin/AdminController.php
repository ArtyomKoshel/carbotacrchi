<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldCoverageStat;
use App\Models\Lot;
use App\Models\LotChange;
use App\Models\ParseFilter;
use App\Models\ParseJob;
use App\Models\ParserSchedule;
use App\Models\ReparseRequest;
use App\Services\FieldMappingsService;
use App\Services\FieldRegistryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule as ValidationRule;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (session('admin_authenticated')) {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function processLogin(Request $request)
    {
        $token    = config('admin.token');
        $password = $request->input('password', '');

        if ($token && hash_equals($token, $password)) {
            $request->session()->put('admin_authenticated', true);
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('admin.login')->withErrors(['password' => 'Неверный пароль']);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_authenticated');
        return redirect()->route('admin.login');
    }

    public function dashboard()
    {
        $t0 = microtime(true);
        $dbg = [];

        $sources = DB::table('lots')
            ->select('source', DB::raw('SUM(is_active) as active'), DB::raw('COUNT(*) as total'), DB::raw('MAX(parsed_at) as last_parsed'))
            ->groupBy('source')
            ->get();
        $dbg['sources'] = round((microtime(true) - $t0) * 1000);

        $lastParsed = $sources->pluck('last_parsed', 'source');

        $t1 = microtime(true);
        $recentChanges = LotChange::orderByDesc('recorded_at')
            ->limit(10)
            ->get();
        $dbg['recentChanges'] = round((microtime(true) - $t1) * 1000);

        $t2 = microtime(true);
        $changeSummary = DB::table('lot_changes')
            ->select('event', DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('event')
            ->pluck('cnt', 'event');
        $dbg['changeSummary'] = round((microtime(true) - $t2) * 1000);

        $t4 = microtime(true);
        $lastScheduled = DB::table('parse_jobs')
            ->select('source', DB::raw('MAX(created_at) as last_run'), DB::raw('MAX(status) as last_status'))
            ->where('created_at', '>=', now()->subDays(30))
            ->where('triggered_by', 'scheduler')
            ->groupBy('source')
            ->get()
            ->keyBy('source');
        $dbg['lastScheduled'] = round((microtime(true) - $t4) * 1000);
        $dbg['total'] = round((microtime(true) - $t0) * 1000);

        return view('admin.dashboard', compact(
            'sources', 'recentChanges', 'changeSummary', 'lastParsed', 'lastScheduled', 'dbg'
        ));
    }

    public function changes(Request $request)
    {
        $event = $request->query('event');

        $query = LotChange::orderByDesc('recorded_at');

        if ($event) {
            $query->where('event', $event);
        }

        $changes = $query->paginate(50)->withQueryString();

        $events = DB::table('lot_changes')
            ->distinct()
            ->pluck('event');

        $dailyChanges = DB::table('lot_changes')
            ->select(DB::raw('DATE(recorded_at) as day'), DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDays(14))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $topChanged = DB::table('lot_changes')
            ->select('lot_id', DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDays(7))
            ->groupBy('lot_id')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();

        return view('admin.changes', compact('changes', 'events', 'event', 'dailyChanges', 'topChanged'));
    }

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

        // Collect available rotation files: parser.log, parser.log.1, ..., parser.log.N
        $rotationFiles = [];
        if ($baseFile) {
            if (file_exists($baseFile)) $rotationFiles[] = ['idx' => 0, 'path' => $baseFile, 'label' => basename($baseFile)];
            for ($i = 1; $i <= 10; $i++) {
                $path = $baseFile . '.' . $i;
                if (file_exists($path)) $rotationFiles[] = ['idx' => $i, 'path' => $path, 'label' => basename($baseFile) . '.' . $i];
                else break;
            }
        }

        // Collect per-job log files from /logs/jobs/ directory
        $jobFiles = [];
        $jobFile  = trim($request->query('job', ''));
        if ($baseFile) {
            $jobDir = dirname($baseFile) . '/jobs';
            if (is_dir($jobDir)) {
                $found = glob($jobDir . '/job-*.log');
                if ($found) {
                    usort($found, fn($a, $b) => filemtime($b) - filemtime($a)); // newest first
                    foreach ($found as $jf) {
                        $jobFiles[] = ['path' => $jf, 'label' => basename($jf), 'size' => filesize($jf)];
                    }
                }
            }
        }

        $logFile = $baseFile;
        if ($jobFile) {
            // Job file takes priority over rotation file index
            $logFile = dirname($baseFile) . '/jobs/' . basename($jobFile);
            if (!file_exists($logFile)) $logFile = $baseFile;
        } elseif ($fileIdx > 0) {
            $found = array_filter($rotationFiles, fn($f) => $f['idx'] === $fileIdx);
            if ($found) $logFile = reset($found)['path'];
        }

        $lines      = [];
        $error      = null;
        $totalLines = 0;
        $totalPages = 1;

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

        return view('admin.logs', compact('lines', 'error', 'level', 'search', 'source', 'fileIdx', 'rotationFiles', 'maxLines', 'page', 'totalLines', 'totalPages', 'jobFiles', 'jobFile'));
    }

    public function stats()
    {
        $dailyChanges = DB::table('lot_changes')
            ->select(DB::raw('DATE(recorded_at) as day'), DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDays(14))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $topChanged = DB::table('lot_changes')
            ->select('lot_id', DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDays(7))
            ->groupBy('lot_id')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();

        return view('admin.stats', compact('dailyChanges', 'topChanged'));
    }

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

        $recent = ReparseRequest::orderByDesc('created_at')->limit(20)->get();

        return view('admin.lots', compact('lots', 'q', 'recent'));
    }

    public function reparseLot(Request $request, string $lotId)
    {
        $exists = DB::table('lots')->where('id', $lotId)->exists();
        if (!$exists) {
            return redirect()->route('admin.lots')
                ->withErrors(['lot_id' => "Lot {$lotId} not found"]);
        }

        $pending = ReparseRequest::where('lot_id', $lotId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if (!$pending) {
            ReparseRequest::create(['lot_id' => $lotId, 'status' => 'pending']);
        }

        return redirect()->route('admin.lots', ['q' => $lotId])
            ->with('success', "Re-parse queued for {$lotId}");
    }

    public function reparseStatus(string $id)
    {
        $req = ReparseRequest::findOrFail($id);
        return response()->json([
            'status' => $req->status,
            'result' => $req->result,
            'updated_at' => $req->updated_at?->toISOString(),
        ]);
    }

    public function jobs(Request $request)
    {
        $jobs = ParseJob::orderByDesc('created_at')->paginate(30);
        $sources = array_values(config('auction.sources', ['kbcha']));
        return view('admin.jobs', compact('jobs', 'sources'));
    }

    public function launchJob(Request $request)
    {
        $source = $request->input('source');
        $filters = array_filter([
            'max_pages' => (int) $request->input('max_pages', 0) ?: null,
            'maker'     => $request->input('maker') ?: null,
        ]);

        $job = ParseJob::create([
            'source'       => $source,
            'status'       => 'pending',
            'filters'      => $filters ?: null,
            'triggered_by' => 'admin',
        ]);

        return redirect()->route('admin.jobs')
            ->with('success', "Job #{$job->id} queued for {$source}");
    }

    public function cancelJob(Request $request, int $id)
    {
        ParseJob::where('id', $id)->whereIn('status', ['pending', 'running'])
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

            // Phase 1: DB-poll until job starts running (or finishes)
            $waitDeadline = time() + 120;
            while (time() < $waitDeadline) {
                $job = ParseJob::find($id);
                if (!$job) return;
                $send(['job_id' => $id, 'status' => $job->status]);
                if ($job->status !== 'pending') break;
                sleep(1);
            }

            if (!$job || in_array($job->status, ['done', 'error', 'cancelled'])) return;

            // Phase 2: Redis subscribe for live progress
            $channel  = "parse_progress:{$job->source}";
            $redis    = Redis::connection('default')->client();
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 30);
            $deadline = time() + 1800;

            try {
                $redis->subscribe([$channel], function ($r, $chan, $message)
                        use ($id, $send, &$deadline) {
                    $data = json_decode($message, true);
                    if (($data['job_id'] ?? null) != $id) {
                        return time() < $deadline ? null : false;
                    }
                    $send($data);
                    if (in_array($data['status'] ?? '', ['done', 'error', 'cancelled'])) {
                        return false;
                    }
                    return time() < $deadline ? null : false;
                });
            } catch (\RedisException $e) {
                // Read timeout expired — stream ended cleanly
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function jobEvents(int $id)
    {
        $job = ParseJob::findOrFail($id);
        $since = ($job->created_at ?? now())->copy()->subSeconds(5);

        // All lots touched during this job (upserted)
        $lots = DB::table('lots')
            ->where('source', $job->source)
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'make', 'model', 'year', 'price', 'mileage', 'updated_at']);

        // Lots that had actual field changes
        $changedIds = DB::table('lot_changes')
            ->where('source', $job->source)
            ->where('recorded_at', '>=', $since)
            ->pluck('lot_id')
            ->unique()
            ->toArray();

        return response()->json([
            'job_id'     => $id,
            'status'     => $job->status,
            'total'      => $lots->count(),
            'changed'    => count($changedIds),
            'lots'       => $lots->map(fn ($l) => [
                'id'      => $l->id,
                'title'   => trim(($l->make ?? '') . ' ' . ($l->model ?? '') . ' ' . ($l->year ?? '')),
                'price'   => $l->price,
                'mileage' => $l->mileage,
                'changed' => in_array($l->id, $changedIds),
            ]),
        ]);
    }

    public function jobDetail(int $id)
    {
        $job = ParseJob::findOrFail($id);

        // Structured stats from job_stats table (if saved)
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

        $level      = $request->query('level', '');
        $search     = trim($request->query('search', ''));
        $page       = max(0, (int) $request->query('page', 0));
        $perPage    = min((int) $request->query('limit', 500), 5000);
        $sinceRaw   = max(0, (int) $request->query('since_raw_line', 0));

        // Read all lines (job logs are bounded — typically <50k lines)
        $raw = @file($jobLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            return response()->json(['lines' => [], 'error' => 'Cannot read log file']);
        }

        $totalRaw  = count($raw);
        $fileSize  = filesize($jobLogPath);

        // Incremental mode: only process lines added since last fetch
        if ($sinceRaw > 0 && !$level && !$search && $page === 0) {
            $newLines = array_slice($raw, $sinceRaw);
            return response()->json([
                'lines'         => array_values($newLines),
                'total'         => count($newLines),
                'total_raw'     => $totalRaw,
                'next_raw_line' => $totalRaw,
                'page'          => 0,
                'total_pages'   => 1,
                'per_page'      => $perPage,
                'file_size'     => $fileSize,
            ]);
        }

        // Full mode — filter + paginate
        $filtered = $raw;
        if ($level) {
            $filtered = array_values(array_filter($filtered, fn($l) => str_contains($l, "[{$level}]")));
        }
        if ($search) {
            $filtered = array_values(array_filter($filtered, fn($l) => stripos($l, $search) !== false));
        }

        $totalFiltered = count($filtered);
        $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
        $page = min($page, $totalPages - 1);
        $pageLines = array_slice($filtered, $page * $perPage, $perPage);

        return response()->json([
            'lines'         => $pageLines,
            'total'         => $totalFiltered,
            'total_raw'     => $totalRaw,
            'next_raw_line' => $totalRaw,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'per_page'      => $perPage,
            'file_size'     => $fileSize,
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
            // Clear base file
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
                $cleared++;
            }
            // Delete all rotation backups (.1, .2, ...)
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

    public function logsDownload(Request $request)
    {
        $logFile = config('admin.log_file');
        if (!file_exists($logFile)) {
            abort(404, 'Log file not found');
        }

        $level  = $request->query('level', '');
        $search = trim($request->query('search', ''));
        $source = trim($request->query('source', ''));

        // No filters — stream the raw file directly
        if (!$level && !$search && !$source) {
            return response()->download($logFile, 'parser-' . now()->format('Ymd-His') . '.log');
        }

        // Apply filters and return only matching lines
        $all   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = [];
        foreach ($all as $line) {
            if ($level  && !str_contains($line, "[{$level}]"))  continue;
            if ($search && !str_contains($line, $search))        continue;
            if ($source && !str_contains($line, $source))        continue;
            $lines[] = $line;
        }

        $suffix = implode('-', array_filter([$level, $source, $search ? 'filtered' : '']));
        $filename = 'parser-' . now()->format('Ymd-His') . ($suffix ? "-{$suffix}" : '') . '.log';

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function fieldStats()
    {
        $errors = [];
        $ttl     = 1800; // 30 min — accuracy data changes slowly
        $ttlSlow = 3600; // 1 h  — JSON_EXTRACT queries are expensive
        $cachedAt = cache()->get('accuracy:cached_at');

        // ── 1. lots columns ───────────────────────────────────────────────
        try {
            $lotsStats = cache()->remember('accuracy:lots', $ttl, fn() => DB::select("
                SELECT
                    source,
                    COUNT(*) AS total,
                    SUM(is_active)                                          AS active,
                    SUM(trim         IS NOT NULL AND trim != '')            AS trim,
                    SUM(vin          IS NOT NULL AND vin != '')             AS vin,
                    SUM(plate_number IS NOT NULL AND plate_number != '')    AS plate_number,
                    SUM(body_type    IS NOT NULL AND body_type != '')       AS body_type,
                    SUM(fuel         IS NOT NULL AND fuel != '')            AS fuel,
                    SUM(transmission IS NOT NULL AND transmission != '')    AS transmission,
                    SUM(drive_type   IS NOT NULL AND drive_type != '')      AS drive_type,
                    SUM(engine_volume IS NOT NULL)                          AS engine_volume,
                    SUM(mileage > 0)                                        AS mileage,
                    SUM(color        IS NOT NULL AND color != '')           AS color,
                    SUM(seat_color   IS NOT NULL AND seat_color != '')      AS seat_color,
                    SUM(has_accident IS NOT NULL)                           AS has_accident,
                    SUM(insurance_count IS NOT NULL)                        AS insurance_count,
                    SUM(owners_count IS NOT NULL)                           AS owners_count,
                    SUM(flood_history IS NOT NULL)                          AS flood_history,
                    SUM(total_loss_history IS NOT NULL)                     AS total_loss_history,
                    SUM(registration_date IS NOT NULL)                      AS registration_date,
                    SUM(price > 0)                                          AS price,
                    SUM(sell_type IS NOT NULL AND sell_type != '')           AS sell_type,
                    SUM(registration_year_month IS NOT NULL AND registration_year_month > 0) AS registration_year_month,
                    SUM(lien_status IS NOT NULL AND lien_status != '')      AS lien_status,
                    SUM(lien_status IS NOT NULL AND lien_status <> 'clean' AND lien_status <> '') AS lien_not_clean,
                    SUM(seizure_status IS NOT NULL AND seizure_status != '') AS seizure_status,
                    SUM(seizure_status IS NOT NULL AND seizure_status <> 'clean' AND seizure_status <> '') AS seizure_not_clean,
                    SUM(repair_cost IS NOT NULL)                             AS repair_cost,
                    SUM(repair_cost IS NOT NULL AND repair_cost > 0)        AS repair_cost_positive,
                    SUM(dealer_name  IS NOT NULL AND dealer_name != '')     AS dealer_name,
                    SUM(dealer_phone IS NOT NULL AND dealer_phone != '')    AS dealer_phone,
                    SUM(dealer_company IS NOT NULL AND dealer_company != '') AS dealer_company,
                    SUM(location     IS NOT NULL AND location != '')        AS location,
                    SUM(options      IS NOT NULL AND JSON_LENGTH(options) > 0) AS has_options,
                    SUM(image_url    IS NOT NULL AND image_url != '')       AS image_url,
                    SUM(fuel_economy IS NOT NULL AND fuel_economy > 0)      AS fuel_economy,
                    SUM(warranty_text IS NOT NULL AND warranty_text != '')   AS warranty_text
                FROM lots WHERE is_active = 1
                GROUP BY source
            "));
        } catch (\Exception $e) {
            $lotsStats = [];
            $errors[] = 'lots: ' . $e->getMessage();
        }

        // ── 2. raw_data JSON keys ─────────────────────────────────────────
        try {
            $rawStats = cache()->remember('accuracy:raw', $ttlSlow, fn() => DB::select("
                SELECT
                    source,
                    COUNT(*) AS total,
                    SUM(JSON_EXTRACT(raw_data, '$.photos')             IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.photos')) <> 'NULL') AS photos,
                    SUM(JSON_EXTRACT(raw_data, '$.engine_code')        IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.engine_code')) <> 'NULL') AS engine_code,
                    SUM(JSON_EXTRACT(raw_data, '$.warranty_type')      IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.warranty_type')) <> 'NULL') AS warranty_type,
                    SUM(JSON_EXTRACT(raw_data, '$.recall')             IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.recall')) <> 'NULL') AS recall,
                    SUM(JSON_EXTRACT(raw_data, '$.recall_status')      IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.recall_status')) <> 'NULL') AS recall_status,
                    SUM(JSON_EXTRACT(raw_data, '$.car_state')          IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.car_state')) <> 'NULL') AS car_state,
                    SUM(JSON_EXTRACT(raw_data, '$.mechanical_issues')  IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.mechanical_issues')) <> 'NULL') AS mechanical_issues,
                    SUM(JSON_EXTRACT(raw_data, '$.diagnosis_center')   IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.diagnosis_center')) <> 'NULL') AS diagnosis_center,
                    SUM(JSON_EXTRACT(raw_data, '$.inspect_vehicle_id') IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.inspect_vehicle_id')) <> 'NULL') AS inspect_vehicle_id,
                    SUM(JSON_EXTRACT(raw_data, '$.drive_type')         IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.drive_type')) <> 'NULL') AS drive_type_raw,
                    SUM(JSON_EXTRACT(raw_data, '$.photo_count')        IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(raw_data, '$.photo_count')) <> 'NULL') AS photo_count
                FROM lots WHERE is_active = 1
                GROUP BY source
            "));
        } catch (\Exception $e) {
            $rawStats = [];
            $errors[] = 'raw_data: ' . $e->getMessage();
        }

        // ── 3. lot_inspections per source ─────────────────────────────────
        try {
            $inspStats = cache()->remember('accuracy:insp', $ttl, fn() => DB::select("
                SELECT
                    l.source,
                    COUNT(DISTINCT l.id)          AS total_lots,
                    COUNT(DISTINCT li.lot_id)     AS lots_with_insp,
                    COUNT(DISTINCT CASE WHEN li.cert_no            IS NOT NULL THEN li.lot_id END) AS cert_no,
                    COUNT(DISTINCT CASE WHEN li.inspection_date    IS NOT NULL THEN li.lot_id END) AS inspection_date,
                    COUNT(DISTINCT CASE WHEN li.valid_from         IS NOT NULL THEN li.lot_id END) AS valid_from,
                    COUNT(DISTINCT CASE WHEN li.valid_until        IS NOT NULL THEN li.lot_id END) AS valid_until,
                    COUNT(DISTINCT CASE WHEN li.inspection_mileage IS NOT NULL THEN li.lot_id END) AS inspection_mileage,
                    COUNT(DISTINCT CASE WHEN li.has_accident       IS NOT NULL THEN li.lot_id END) AS has_accident,
                    COUNT(DISTINCT CASE WHEN li.has_accident = 1              THEN li.lot_id END) AS accident_true,
                    COUNT(DISTINCT CASE WHEN li.has_outer_damage   IS NOT NULL THEN li.lot_id END) AS has_outer_damage,
                    COUNT(DISTINCT CASE WHEN li.has_outer_damage = 1          THEN li.lot_id END) AS outer_damage_true,
                    COUNT(DISTINCT CASE WHEN li.outer_detail IS NOT NULL AND li.outer_detail <> '' THEN li.lot_id END) AS outer_detail,
                    COUNT(DISTINCT CASE WHEN li.has_flood          IS NOT NULL THEN li.lot_id END) AS has_flood,
                    COUNT(DISTINCT CASE WHEN li.has_tuning         IS NOT NULL THEN li.lot_id END) AS has_tuning,
                    COUNT(DISTINCT CASE WHEN li.accident_detail IS NOT NULL AND li.accident_detail <> '' THEN li.lot_id END) AS accident_detail,
                    COUNT(DISTINCT CASE WHEN li.report_url        IS NOT NULL THEN li.lot_id END) AS report_url
                FROM lots l
                LEFT JOIN lot_inspections li ON li.lot_id = l.id
                WHERE l.is_active = 1
                GROUP BY l.source
            "));
        } catch (\Exception $e) {
            $inspStats = [];
            $errors[] = 'lot_inspections: ' . $e->getMessage();
        }

        // ── 4. lot_photos per source ──────────────────────────────────────
        try {
            $photoStats = cache()->remember('accuracy:photos', $ttl, fn() => DB::select("
                SELECT
                    l.source,
                    COUNT(DISTINCT l.id)      AS total_lots,
                    COUNT(DISTINCT lp.lot_id) AS lots_with_photos,
                    COUNT(lp.id)              AS total_photos,
                    ROUND(COUNT(lp.id) / NULLIF(COUNT(DISTINCT lp.lot_id), 0), 1) AS avg_photos_per_lot
                FROM lots l
                LEFT JOIN lot_photos lp ON lp.lot_id = l.id
                WHERE l.is_active = 1
                GROUP BY l.source
            "));
        } catch (\Exception $e) {
            $photoStats = [];
            $errors[] = 'lot_photos: ' . $e->getMessage();
        }

        // Stamp cache time on first successful full load
        if (!$cachedAt && empty($errors)) {
            cache()->put('accuracy:cached_at', now()->timestamp, $ttl);
        }
        $cachedAt = cache()->get('accuracy:cached_at');

        return view('admin.accuracy', compact(
            'lotsStats', 'rawStats', 'inspStats', 'photoStats', 'errors', 'cachedAt'
        ));
    }

    public function accuracyRefresh()
    {
        foreach (['accuracy:lots', 'accuracy:raw', 'accuracy:insp', 'accuracy:photos', 'accuracy:cached_at'] as $key) {
            cache()->forget($key);
        }
        return redirect()->route('admin.accuracy')->with('success', 'Cache cleared — data will reload from DB');
    }

    public function schedules()
    {
        $schedules = ParserSchedule::orderBy('source')->get()->keyBy('source');
        $sources   = array_values(config('auction.sources', ['kbcha']));
        return view('admin.schedules', compact('schedules', 'sources'));
    }

    public function updateSchedule(Request $request, string $source)
    {
        try {
            ParserSchedule::updateOrCreate(['source' => $source], [
                'enabled'          => (bool) $request->input('enabled'),
                'schedule'         => $request->input('schedule') ?? '',
                'interval_minutes' => (int) $request->input('interval_minutes', 60),
                'max_pages'        => (int) $request->input('max_pages', 0),
                'maker_filter'     => $request->input('maker_filter') ?: null,
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
            $resp = Http::connectTimeout(3)->timeout(8)
                ->withHeader('X-Api-Key', $key)
                ->get('https://client-api.floppy.host/v1/rotating/balance');
            if ($resp->successful()) {
                return response()->json($resp->json());
            }
            return response()->json(['error' => 'API error ' . $resp->status()], 502);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Read from end of file in chunks, filtering on the fly.
     * Returns [$matchedLines (newest-first), $bytesScanned, $fileSize].
     */
    private function readFilteredTail(string $path, int $needed, string $level, string $search, string $source): array
    {
        $fp = @fopen($path, 'r');
        if (!$fp) return [[], 0, 0];

        $fileSize = filesize($path);
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $scanned = 0;
        $results = [];
        $remainder = '';
        $chunkSize = 65536; // 64 KB

        while ($pos > 0 && count($results) < $needed) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $chunk = fread($fp, $read);
            $scanned += $read;

            $block = $chunk . $remainder;
            $lines = explode("\n", $block);
            $remainder = array_shift($lines); // first element is potentially incomplete

            // iterate newest→oldest (end of chunk is most recent)
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                if ($line === '') continue;
                if ($level  && !str_contains($line, "[{$level}]")) continue;
                if ($search && !str_contains($line, $search))      continue;
                if ($source && !str_contains($line, $source))      continue;
                $results[] = $line;
                if (count($results) >= $needed) break;
            }
        }

        // handle the very first line of the file
        if ($remainder !== '' && count($results) < $needed) {
            $line = $remainder;
            $pass = true;
            if ($level  && !str_contains($line, "[{$level}]")) $pass = false;
            if ($search && !str_contains($line, $search))      $pass = false;
            if ($source && !str_contains($line, $source))      $pass = false;
            if ($pass) $results[] = $line;
            $scanned = $fileSize;
        }

        fclose($fp);
        return [$results, $scanned, $fileSize];
    }

    // ── Filters ──────────────────────────────────────────────────────────

    /** GET /admin/filters — list and manage parse_filters rules. */
    public function filters(FieldRegistryService $registry)
    {
        $filters = ParseFilter::orderBy('priority')->orderBy('id')->get();

        $recentHits = DB::table('lot_changes')
            ->where('event', 'like', 'deactivated_filter%')
            ->where('recorded_at', '>=', now()->subDay())
            ->count();

        return view('admin.filters', [
            'filters'        => $filters,
            'schema'         => $registry->schema(),
            'groupedFields'  => $registry->groupedFilterable(),
            'operators'      => ParseFilter::OPERATORS,
            'operatorLabels' => ParseFilter::OPERATOR_LABELS,
            'actions'        => ParseFilter::ACTIONS,
            'actionLabels'   => ParseFilter::ACTION_LABELS,
            'sources'        => ParseFilter::SOURCES,
            'recentHits'     => $recentHits,
        ]);
    }

    /** GET /admin/fields-schema.json — JSON export of the Python field registry (for UI JS). */
    public function fieldsSchema(FieldRegistryService $registry): \Illuminate\Http\JsonResponse
    {
        return response()->json($registry->schema())
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** GET /admin/fields — unified mapping catalogue + coverage stats. */
    public function fields(FieldMappingsService $mappings, FieldRegistryService $registry)
    {
        $schema   = $mappings->schema();
        $mappingByAttr = [];
        foreach ($schema['mappings'] ?? [] as $m) {
            $mappingByAttr[$m['attribute']] = $m;
        }

        $registrySchema = $registry->schema();
        $registryByName = [];
        foreach ($registrySchema['fields'] ?? [] as $f) {
            $registryByName[$f['name']] = $f;
        }

        // Coverage stats — read from pre-computed table (fast)
        $coverageRaw = FieldCoverageStat::orderBy('source')->orderBy('field_name')->get();
        $coverageByField = [];
        $sources = [];
        $computedAt = null;
        foreach ($coverageRaw as $stat) {
            $coverageByField[$stat->field_name][$stat->source] = [
                'filled'  => $stat->filled_lots,
                'total'   => $stat->total_lots,
                'pct'     => $stat->coverage_pct,
            ];
            $sources[$stat->source] = true;
            if ($computedAt === null || $stat->computed_at->gt($computedAt)) {
                $computedAt = $stat->computed_at;
            }
        }
        $sources = array_keys($sources);
        sort($sources);

        // Build the unified rows: one per field, combining all three sources
        $unified = [];
        $allFieldNames = array_unique(array_merge(
            array_keys($registryByName),
            array_keys($mappingByAttr),
            array_keys($coverageByField),
        ));
        foreach ($allFieldNames as $name) {
            $reg = $registryByName[$name] ?? null;
            $map = $mappingByAttr[$name] ?? null;
            $unified[] = [
                'name'         => $name,
                'db_column'    => $map['db_column']   ?? $reg['column']     ?? $name,
                'dtype'        => $reg['dtype']       ?? $map['dtype']      ?? '?',
                'category'     => $reg['category']    ?? $map['category']   ?? 'other',
                'filterable'   => (bool) ($reg['filterable'] ?? $map['filterable'] ?? false),
                'tracked'      => (bool) ($reg['tracked']    ?? false),
                'description'  => $reg['description'] ?? $map['notes'] ?? '',
                'extractions'  => $map['extractions'] ?? [],
                'coverage'     => $coverageByField[$name] ?? [],
            ];
        }

        // Group by category, then sort by name within group
        usort($unified, fn($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);
        $grouped = [];
        foreach ($unified as $u) {
            $grouped[$u['category']][] = $u;
        }
        ksort($grouped);

        $totals = [];
        foreach ($sources as $src) {
            $totals[$src] = (int) DB::table('lots')
                ->where('source', $src)->where('is_active', 1)->count();
        }

        return view('admin.fields', [
            'grouped'     => $grouped,
            'sources'     => $sources,
            'totals'      => $totals,
            'totalFields' => count($unified),
            'computedAt'  => $computedAt,
            'version'     => $schema['version'] ?? 0,
        ]);
    }

    /** POST /admin/fields/recompute — run coverage job synchronously. */
    public function fieldsRecompute()
    {
        Artisan::call('fields:compute-coverage');
        return redirect()->route('admin.fields')
            ->with('success', 'Coverage stats recomputed.');
    }

    /** POST /admin/filters — create a new rule. */
    public function createFilter(Request $request)
    {
        $data = $this->validateFilterPayload($request);
        ParseFilter::create($data);
        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$data['name']}' created — parser picks it up within 60s.");
    }

    /** PUT /admin/filters/{id} — update an existing rule. */
    public function updateFilter(Request $request, int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $data = $this->validateFilterPayload($request, $id);
        $filter->update($data);
        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$filter->name}' updated.");
    }

    /** DELETE /admin/filters/{id} — delete a rule. */
    public function deleteFilter(int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $name = $filter->name;
        $filter->delete();
        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$name}' deleted.");
    }

    /** PATCH /admin/filters/{id}/toggle — quickly flip enabled. */
    public function toggleFilter(int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $filter->enabled = !$filter->enabled;
        $filter->save();
        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$filter->name}' " . ($filter->enabled ? 'enabled' : 'disabled') . '.');
    }


    /**
     * Validate and normalize the filter payload from the form.
     * $ignoreId — the current rule id to exempt from the unique-name rule on edit.
     */
    private function validateFilterPayload(Request $request, ?int $ignoreId = null): array
    {
        $nameRule = ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/i'];
        $nameRule[] = ValidationRule::unique('parse_filters', 'name')
            ->ignore($ignoreId);

        $validated = $request->validate([
            'name'        => $nameRule,
            'source'      => ['nullable', ValidationRule::in(ParseFilter::SOURCES)],
            'field'       => ['required', 'string', 'max:64'],
            'operator'    => ['required', ValidationRule::in(ParseFilter::OPERATORS)],
            'value'       => ['nullable', 'string', 'max:4096'],
            'action'      => ['required', ValidationRule::in(ParseFilter::ACTIONS)],
            'priority'    => ['required', 'integer', 'min:0', 'max:10000'],
            'enabled'     => ['nullable'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // Normalize: enabled checkbox → bool; value → JSON-encoded when parseable
        $validated['enabled'] = (bool) $request->input('enabled', false);
        $validated['source'] = $validated['source'] ?: null;

        // Accept either raw JSON in the textarea ("123", "\"rental\"", "[1,2]")
        // or a plain scalar. Always store as JSON for unambiguous parsing by Python.
        $raw = $validated['value'] ?? null;
        if ($raw === null || $raw === '') {
            $validated['value'] = null;
        } elseif ($this->isJson($raw)) {
            $validated['value'] = $raw;
        } else {
            // Plain string / number → wrap as JSON literal
            $validated['value'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        return $validated;
    }

    private function isJson(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') return false;
        json_decode($raw);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
