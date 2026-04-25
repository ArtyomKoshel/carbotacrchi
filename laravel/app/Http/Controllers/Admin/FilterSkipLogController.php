<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FilterSkipLog;
use Illuminate\Http\Request;

class FilterSkipLogController extends Controller
{
    public function index(Request $request)
    {
        $query = FilterSkipLog::query();

        // Filter by source
        if ($request->filled('source')) {
            $query->bySource($request->source);
        }

        // Filter by rule_id
        if ($request->filled('rule_id')) {
            $query->byRule($request->rule_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('skipped_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('skipped_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Order and paginate
        $logs = $query->latest()->paginate(50)->withQueryString();

        // Get unique sources for filter dropdown
        $sources = FilterSkipLog::select('source')->distinct()->pluck('source');

        return view('admin.filter-skip-log', compact('logs', 'sources'));
    }

    public function cleanup(Request $request)
    {
        $days = $request->input('days', 30);
        if ($days < 1) {
            return back()->with('error', 'Days must be at least 1');
        }

        $deleted = FilterSkipLog::where('skipped_at', '<', now()->subDays($days))->delete();

        return back()->with('success', "Deleted {$deleted} log entries older than {$days} days");
    }
}
