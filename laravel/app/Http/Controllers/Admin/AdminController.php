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

        $proxyBalance = $this->fetchProxyBalance();

        return view('admin.dashboard', compact(
            'sources', 'recentChanges', 'changeSummary', 'lastParsed', 'proxyBalance'
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
        $logFile = config('admin.log_file');
        $maxLines = config('admin.log_lines', 300);
        $level = $request->query('level', '');

        $lines = [];
        $error = null;

        if (!file_exists($logFile)) {
            $error = "Log file not found: {$logFile}";
        } else {
            $all = $this->tailFile($logFile, $maxLines * 3);
            foreach ($all as $line) {
                if ($level && !str_contains($line, "[{$level}]")) {
                    continue;
                }
                $lines[] = $line;
                if (count($lines) >= $maxLines) {
                    break;
                }
            }
            $lines = array_reverse($lines);
        }

        return view('admin.logs', compact('lines', 'error', 'level'));
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
            return back()->withErrors(['lot_id' => "Lot {$lotId} not found"]);
        }

        $pending = ReparseRequest::where('lot_id', $lotId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if (!$pending) {
            ReparseRequest::create(['lot_id' => $lotId, 'status' => 'pending']);
        }

        return redirect()->route('admin.lots', ['token' => $request->query('token'), 'q' => $lotId])
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
        $sources = array_keys(config('auction.sources', ['kbcha' => true]));
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

        return redirect()->route('admin.jobs', ['token' => $request->query('token')])
            ->with('success', "Job #{$job->id} queued for {$source}");
    }

    public function cancelJob(Request $request, int $id)
    {
        ParseJob::where('id', $id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        return back()->with('success', "Job #{$id} cancelled");
    }

    public function jobProgress(int $id)
    {
        $job = ParseJob::findOrFail($id);

        return response()->stream(function () use ($job) {
            $channel = "parse_progress:{$job->source}";
            $redis   = Redis::connection('default');

            set_time_limit(0);
            $deadline = time() + 120;

            $redis->subscribe([$channel], function ($message) use ($job, &$deadline) {
                $data = json_decode($message, true);
                if (($data['job_id'] ?? null) == $job->id) {
                    echo "data: " . $message . "\n\n";
                    ob_flush();
                    flush();
                    if (in_array($data['status'] ?? '', ['done', 'error'])) {
                        $deadline = 0;
                        return false;
                    }
                }
                if (time() > $deadline) {
                    return false;
                }
            });
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function schedules()
    {
        $schedules = ParserSchedule::orderBy('source')->get()->keyBy('source');
        $sources   = array_keys(config('auction.sources', ['kbcha' => true]));
        return view('admin.schedules', compact('schedules', 'sources'));
    }

    public function updateSchedule(Request $request, string $source)
    {
        ParserSchedule::updateOrCreate(['source' => $source], [
            'enabled'          => (bool) $request->input('enabled'),
            'schedule'         => $request->input('schedule', ''),
            'interval_minutes' => (int) $request->input('interval_minutes', 60),
            'max_pages'        => (int) $request->input('max_pages', 0),
            'maker_filter'     => $request->input('maker_filter') ?: null,
        ]);

        return back()->with('success', "Schedule for {$source} updated. Restart parser to apply.");
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

    private function tailFile(string $path, int $lines): array
    {
        $fp = fopen($path, 'r');
        if (!$fp) {
            return [];
        }

        $buffer = '';
        $count  = 0;

        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);

        while ($pos > 0 && $count < $lines) {
            $read = min(4096, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $chunk  = fread($fp, $read);
            $buffer = $chunk . $buffer;
            $count  = substr_count($buffer, "\n");
        }

        fclose($fp);

        $result = explode("\n", $buffer);
        $result = array_filter($result, fn($l) => $l !== '');

        return array_slice(array_values($result), -$lines);
    }
}
