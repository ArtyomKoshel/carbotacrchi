<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\LotChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (session('admin_user_id')) {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function processLogin(Request $request)
    {
        $username = trim($request->input('username', ''));
        $password = $request->input('password', '');

        $user = AdminUser::where('username', $username)->first();

        if ($user && $user->checkPassword($password)) {
            $request->session()->regenerate();
            $request->session()->put('admin_user_id', $user->id);
            $request->session()->put('admin_role', $user->role);
            $request->session()->put('admin_username', $user->username);
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('admin.login')
            ->withErrors(['password' => 'Неверный логин или пароль'])
            ->withInput(['username' => $username]);
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        return redirect()->route('admin.login');
    }

    public function dashboard()
    {
        $t0 = microtime(true);
        $dbg = [];

        $activeSources = array_values(array_filter(
            (array) config('auction.sources', ['encar']),
            fn ($source) => is_string($source) && $source !== '' && $source !== 'kbcha'
        ));

        $sources = DB::table('lots')
            ->select('source', DB::raw('SUM(is_active) as active'), DB::raw('COUNT(*) as total'), DB::raw('MAX(parsed_at) as last_parsed'))
            ->whereIn('source', $activeSources)
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
}
