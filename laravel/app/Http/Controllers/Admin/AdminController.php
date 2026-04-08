<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\LotChange;
use App\Models\ParseJob;
use App\Models\ParserSchedule;
use App\Models\ReparseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

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
        $sources = DB::table('lots')
            ->select('source', DB::raw('SUM(is_active) as active'), DB::raw('COUNT(*) as total'))
            ->groupBy('source')
            ->get();

        $recentChanges = LotChange::orderByDesc('recorded_at')
            ->limit(10)
            ->get();

        $changeSummary = DB::table('lot_changes')
            ->select('event', DB::raw('COUNT(*) as cnt'))
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('event')
            ->pluck('cnt', 'event');

        $lastParsed = DB::table('lots')
            ->select('source', DB::raw('MAX(parsed_at) as last_parsed'))
            ->groupBy('source')
            ->pluck('last_parsed', 'source');

        $lastScheduled = DB::table('parse_jobs')
            ->select('source', DB::raw('MAX(created_at) as last_run'), DB::raw('MAX(status) as last_status'))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(filters, '$.triggered_by')) = 'scheduler'")
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $proxyBalance = $this->fetchProxyBalance();

        return view('admin.dashboard', compact(
            'sources', 'recentChanges', 'changeSummary', 'lastParsed', 'lastScheduled', 'proxyBalance'
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

        return view('admin.changes', compact('changes', 'events', 'event'));
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
            ->get(['id', 'make', 'model', 'year', 'price', 'price_krw', 'mileage', 'updated_at']);

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

        // ── 1. lots columns ───────────────────────────────────────────────
        try {
            $lotsStats = DB::select("
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
                    SUM(mileage      IS NOT NULL)                           AS mileage,
                    SUM(color        IS NOT NULL AND color != '')           AS color,
                    SUM(seat_color   IS NOT NULL AND seat_color != '')      AS seat_color,
                    SUM(has_accident IS NOT NULL)                           AS has_accident,
                    SUM(insurance_count IS NOT NULL)                        AS insurance_count,
                    SUM(owners_count IS NOT NULL)                           AS owners_count,
                    SUM(flood_history IS NOT NULL)                          AS flood_history,
                    SUM(total_loss_history IS NOT NULL)                     AS total_loss_history,
                    SUM(registration_date IS NOT NULL)                      AS registration_date,
                    SUM(price        IS NOT NULL)                           AS price,
                    SUM(lien_status  IS NOT NULL AND lien_status <> 'clean') AS lien_not_clean,
                    SUM(seizure_status IS NOT NULL AND seizure_status <> 'clean') AS seizure_not_clean,
                    SUM(repair_cost  IS NOT NULL AND repair_cost > 0)       AS repair_cost,
                    SUM(dealer_name  IS NOT NULL AND dealer_name != '')     AS dealer_name,
                    SUM(dealer_phone IS NOT NULL AND dealer_phone != '')    AS dealer_phone,
                    SUM(dealer_company IS NOT NULL AND dealer_company != '') AS dealer_company,
                    SUM(location     IS NOT NULL AND location != '')        AS location,
                    SUM(options      IS NOT NULL AND JSON_LENGTH(options) > 0) AS has_options,
                    SUM(image_url    IS NOT NULL AND image_url != '')       AS image_url
                FROM lots
                GROUP BY source
            ");
        } catch (\Exception $e) {
            $lotsStats = [];
            $errors[] = 'lots: ' . $e->getMessage();
        }

        // ── 2. raw_data JSON keys ─────────────────────────────────────────
        try {
            $rawStats = DB::select("
                SELECT
                    source,
                    COUNT(*) AS total,
                    SUM(JSON_EXTRACT(raw_data, '$.photos')             IS NOT NULL) AS photos,
                    SUM(JSON_EXTRACT(raw_data, '$.engine_code')        IS NOT NULL) AS engine_code,
                    SUM(JSON_EXTRACT(raw_data, '$.warranty_type')      IS NOT NULL) AS warranty_type,
                    SUM(JSON_EXTRACT(raw_data, '$.recall')             IS NOT NULL) AS recall,
                    SUM(JSON_EXTRACT(raw_data, '$.recall_status')      IS NOT NULL) AS recall_status,
                    SUM(JSON_EXTRACT(raw_data, '$.car_state')          IS NOT NULL) AS car_state,
                    SUM(JSON_EXTRACT(raw_data, '$.mechanical_issues')  IS NOT NULL) AS mechanical_issues,
                    SUM(JSON_EXTRACT(raw_data, '$.diagnosis_center')   IS NOT NULL) AS diagnosis_center,
                    SUM(JSON_EXTRACT(raw_data, '$.inspect_vehicle_id') IS NOT NULL) AS inspect_vehicle_id,
                    SUM(JSON_EXTRACT(raw_data, '$.drive_type')         IS NOT NULL) AS drive_type_raw,
                    SUM(JSON_EXTRACT(raw_data, '$.photo_count')        IS NOT NULL) AS photo_count
                FROM lots
                GROUP BY source
            ");
        } catch (\Exception $e) {
            $rawStats = [];
            $errors[] = 'raw_data: ' . $e->getMessage();
        }

        // ── 3. lot_inspections per source ─────────────────────────────────
        try {
            $inspStats = DB::select("
                SELECT
                    l.source,
                    COUNT(DISTINCT l.id)          AS total_lots,
                    COUNT(DISTINCT li.lot_id)     AS lots_with_insp,
                    SUM(li.cert_no        IS NOT NULL) AS cert_no,
                    SUM(li.inspection_date IS NOT NULL) AS inspection_date,
                    SUM(li.valid_from     IS NOT NULL) AS valid_from,
                    SUM(li.valid_until    IS NOT NULL) AS valid_until,
                    SUM(li.inspection_mileage IS NOT NULL) AS inspection_mileage,
                    SUM(li.has_accident   IS NOT NULL) AS has_accident,
                    SUM(CASE WHEN li.has_accident = 1 THEN 1 ELSE 0 END) AS accident_true,
                    SUM(li.has_outer_damage IS NOT NULL) AS has_outer_damage,
                    SUM(CASE WHEN li.has_outer_damage = 1 THEN 1 ELSE 0 END) AS outer_damage_true,
                    SUM(CASE WHEN li.outer_detail IS NOT NULL AND li.outer_detail <> '' THEN 1 ELSE 0 END) AS outer_detail,
                    SUM(li.has_flood  IS NOT NULL) AS has_flood,
                    SUM(li.has_tuning IS NOT NULL) AS has_tuning,
                    SUM(CASE WHEN li.accident_detail IS NOT NULL AND li.accident_detail <> '' THEN 1 ELSE 0 END) AS accident_detail,
                    SUM(li.report_url IS NOT NULL) AS report_url
                FROM lots l
                LEFT JOIN lot_inspections li ON li.lot_id = l.id
                GROUP BY l.source
            ");
        } catch (\Exception $e) {
            $inspStats = [];
            $errors[] = 'lot_inspections: ' . $e->getMessage();
        }

        // ── 4. lot_photos per source ──────────────────────────────────────
        try {
            $photoStats = DB::select("
                SELECT
                    l.source,
                    COUNT(DISTINCT l.id)      AS total_lots,
                    COUNT(DISTINCT lp.lot_id) AS lots_with_photos,
                    COUNT(lp.id)              AS total_photos
                FROM lots l
                LEFT JOIN lot_photos lp ON lp.lot_id = l.id
                GROUP BY l.source
            ");
        } catch (\Exception $e) {
            $photoStats = [];
            $errors[] = 'lot_photos: ' . $e->getMessage();
        }

        // ── 5. Fields never/rarely filled ────────────────────────────────
        try {
            $neverSeen = DB::select("
                SELECT source,
                    SUM(drive_type    IS NULL OR drive_type = '')     AS no_drive_type,
                    SUM(dealer_company IS NULL OR dealer_company = '') AS no_dealer_company,
                    SUM(repair_cost   IS NULL OR repair_cost = 0)     AS no_repair_cost,
                    COUNT(*) AS total
                FROM lots GROUP BY source
            ");
        } catch (\Exception $e) {
            $neverSeen = [];
            $errors[] = 'neverSeen: ' . $e->getMessage();
        }

        return view('admin.accuracy', compact(
            'lotsStats', 'rawStats', 'inspStats', 'photoStats', 'neverSeen', 'errors'
        ));
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

    private function fetchProxyBalance(): ?array
    {
        $key = config('auction.floppydata_api_key');
        if (!$key) {
            return null;
        }
        try {
            $resp = Http::timeout(5)
                ->withHeader('X-Api-Key', $key)
                ->get('https://client-api.floppy.host/v1/rotating/balance');
            if ($resp->successful()) {
                return $resp->json();
            }
        } catch (\Throwable) {}
        return null;
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
}
