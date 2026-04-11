@extends('admin.layout')
@section('title', "Job #{$job->id}")

@section('content')

<div class="flex items-center gap-3 mb-6">
  <a href="{{ route('admin.jobs') }}" class="text-gray-500 hover:text-white text-sm">← Jobs</a>
  <h1 class="text-lg font-bold text-white">Job #{{ $job->id }}</h1>
  @php
    $badge = match($job->status) {
      'done'      => 'bg-green-900 text-green-400',
      'error'     => 'bg-red-900 text-red-400',
      'running'   => 'bg-yellow-900 text-yellow-400',
      'cancelled' => 'bg-gray-800 text-gray-500',
      default     => 'bg-blue-900/50 text-blue-400',
    };
  @endphp
  <span id="status-badge" class="text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $job->status }}</span>
  <span class="text-xs text-gray-600">{{ $job->source }}</span>
  @if(($job->filters['triggered_by'] ?? 'manual') === 'scheduler')
    <span class="text-xs px-1.5 py-0.5 rounded bg-purple-900/50 text-purple-400">⏱ auto</span>
  @endif
  <span class="text-xs text-gray-600 ml-auto">{{ $job->created_at->format('Y-m-d H:i:s') }}</span>
</div>

@php
  // Prefer $stat (DB) > $job->result (JSON) > $job->progress (live) for display
  $r = $job->result ?? [];
  $p = $job->progress ?? [];
  $total     = $stat->total ?? ($r['total'] ?? ($p['total'] ?? 0));
  $apiTotal  = $stat->api_total ?? ($r['api_total'] ?? ($p['api_total'] ?? 0));
  $cov       = $stat->coverage_pct ?? ($r['coverage_pct'] ?? ($p['pct'] ?? 0));
  $newLots   = $stat->new_lots ?? ($r['new'] ?? ($p['new'] ?? 0));
  $updated   = $stat->updated_lots ?? ($r['updated'] ?? ($p['updated'] ?? 0));
  $stale     = $stat->stale_lots ?? ($r['stale'] ?? 0);
  $errors    = $stat->errors ?? ($r['errors'] ?? ($p['errors'] ?? 0));
  $elapsed   = $stat->elapsed_s ?? ($r['elapsed_s'] ?? ($p['elapsed_s'] ?? 0));
  $avgLot    = $stat->avg_per_lot_s ?? ($r['avg_per_lot_s'] ?? ($p['avg_per_lot_s'] ?? 0));
  $searchT   = $stat->search_time_s ?? ($r['search_time_s'] ?? ($p['search_time_s'] ?? null));
  $enrichT   = $stat->enrich_time_s ?? ($r['enrich_time_s'] ?? ($p['enrich_time_s'] ?? null));
  $pauseT    = $stat->pause_time_s ?? ($r['pause_time_s'] ?? ($p['pause_time_s'] ?? 0));
  $timeStr   = $r['time'] ?? '--';
  $errTypes  = $stat ? json_decode($stat->error_types ?? '{}', true) : ($r['error_types'] ?? []);
  $errLog    = $stat ? json_decode($stat->error_log ?? '[]', true) : ($r['error_log'] ?? []);
@endphp

{{-- Stats grid --}}
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6" id="stats-grid">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Processed</div>
    <div class="text-xl font-bold text-white mt-1" id="s-total">{{ number_format($total) }}</div>
    <div class="text-[10px] text-gray-600 mt-0.5">API: {{ number_format($apiTotal) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Coverage</div>
    <div class="text-xl font-bold mt-1 {{ $cov >= 95 ? 'text-green-400' : ($cov > 0 ? 'text-yellow-400' : 'text-gray-500') }}" id="s-coverage">{{ $cov }}%</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">New</div>
    <div class="text-xl font-bold text-blue-400 mt-1" id="s-new">{{ number_format($newLots) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Updated</div>
    <div class="text-xl font-bold text-gray-300 mt-1" id="s-updated">{{ number_format($updated) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Stale</div>
    <div class="text-xl font-bold text-orange-400 mt-1" id="s-stale">{{ number_format($stale) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Errors</div>
    <div class="text-xl font-bold {{ $errors > 0 ? 'text-red-400' : 'text-gray-500' }} mt-1" id="s-errors">{{ $errors }}</div>
  </div>
</div>

{{-- Timing --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6" id="timing-grid">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Elapsed</div>
    <div class="text-lg font-mono text-white mt-1" id="t-elapsed">{{ $timeStr }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Avg / Lot</div>
    <div class="text-lg font-mono text-gray-300 mt-1" id="t-avg">{{ $avgLot ? ($avgLot . 's') : '--' }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Search + Batch</div>
    <div class="text-lg font-mono text-cyan-400 mt-1" id="t-search">{{ $searchT !== null ? $searchT . 's' : '--' }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Enrichment</div>
    <div class="text-lg font-mono text-purple-400 mt-1" id="t-enrich">{{ $enrichT !== null ? $enrichT . 's' : '--' }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-[10px] text-gray-500 uppercase tracking-wider">Pauses</div>
    <div class="text-lg font-mono {{ $pauseT > 10 ? 'text-yellow-400' : 'text-gray-500' }} mt-1" id="t-pause">{{ $pauseT . 's' }}</div>
  </div>
</div>

{{-- Progress bar --}}
@if($job->status === 'running')
<div class="mb-6">
  <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
    <span id="pb-label">{{ $job->progress['pct'] ?? 0 }}%</span>
    <span id="pb-detail">{{ number_format($job->progress['found_total'] ?? 0) }} / {{ number_format($job->progress['api_total'] ?? 0) }}</span>
  </div>
  <div class="w-full bg-gray-800 rounded-full h-2">
    <div id="pb-bar" class="bg-blue-500 h-2 rounded-full transition-all duration-500" style="width: {{ $job->progress['pct'] ?? 0 }}%"></div>
  </div>
</div>
@endif

{{-- Tabs --}}
<div class="flex border-b border-gray-800 text-sm mb-4">
  <button onclick="switchTab('errors')" id="tab-errors" class="px-4 py-2 text-red-400 border-b-2 border-red-500 font-semibold">
    Errors <span id="err-count" class="text-gray-600 ml-1">{{ count($errLog) }}</span>
  </button>
  <button onclick="switchTab('error-types')" id="tab-error-types" class="px-4 py-2 text-gray-500 hover:text-white">
    Error Types
  </button>
  <button onclick="switchTab('logs')" id="tab-logs" class="px-4 py-2 text-gray-500 hover:text-white">
    Logs
  </button>
</div>

{{-- Error log pane --}}
<div id="pane-errors" class="bg-gray-900 border border-gray-800 rounded-xl p-4 font-mono text-xs space-y-1 max-h-[500px] overflow-y-auto">
  @forelse($errLog as $err)
    <div class="text-red-400/80">{{ $err }}</div>
  @empty
    <div class="text-gray-600">No errors recorded</div>
  @endforelse
</div>

{{-- Error types pane --}}
<div id="pane-error-types" class="hidden bg-gray-900 border border-gray-800 rounded-xl p-4">
  @if(!empty($errTypes))
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      @foreach($errTypes as $type => $cnt)
        <div class="flex items-center justify-between bg-gray-800 rounded-lg px-3 py-2">
          <span class="text-xs text-gray-300 font-mono">{{ $type }}</span>
          <span class="text-xs font-bold {{ $cnt > 5 ? 'text-red-400' : 'text-yellow-400' }}">{{ $cnt }}</span>
        </div>
      @endforeach
    </div>
  @else
    <div class="text-gray-600 text-xs">No error types recorded</div>
  @endif
</div>

{{-- Logs pane --}}
<div id="pane-logs" class="hidden bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  {{-- Toolbar row 1: level + search --}}
  <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-800 flex-wrap">
    <select id="log-level" onchange="logPage=0;loadLogs()" class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-white">
      <option value="">All levels</option>
      <option value="ERROR">Errors</option>
      <option value="WARNING">Warnings</option>
      <option value="INFO">Info</option>
      <option value="DEBUG">Debug</option>
      <option value="STAT">Stats</option>
    </select>
    <input id="log-search" type="text" placeholder="Search..." onkeydown="if(event.key==='Enter'){logPage=0;loadLogs()}"
           class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-white placeholder-gray-600 w-48">
    <button onclick="logPage=0;loadLogs()" class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">Search</button>
    <button onclick="loadLogs()" class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">↻ Refresh</button>
    <button id="log-ar-btn" onclick="toggleLogAutoRefresh()" class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-green-400">
      Auto: <span id="log-ar-state">OFF</span>
    </button>
    <button onclick="scrollLogBottom()" class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-white" title="Scroll to end">↓ End</button>
    <a href="/admin/jobs/{{ $job->id }}/log?limit=50000" target="_blank"
       class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-white ml-auto">↓ Raw</a>
    <span id="log-meta" class="text-xs text-gray-600"></span>
  </div>
  {{-- Toolbar row 2: pagination --}}
  <div id="log-pagination" class="hidden flex items-center gap-1 px-4 py-2 border-b border-gray-800">
    <button onclick="logPage=0;loadLogs()" class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">«</button>
    <button onclick="logPage=Math.max(0,logPage-1);loadLogs()" class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">‹</button>
    <span id="log-page-info" class="text-xs text-gray-500 mx-2"></span>
    <button onclick="logPage=Math.min(logTotalPages-1,logPage+1);loadLogs()" class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">›</button>
    <button onclick="logPage=logTotalPages-1;loadLogs()" class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">»</button>
  </div>
  <div id="log-content" class="p-4 font-mono text-xs space-y-0.5 max-h-[600px] overflow-y-auto">
    <div class="text-gray-600">Switch to this tab to load logs</div>
  </div>
</div>

<script>
const JOB_ID = {{ $job->id }};
const JOB_STATUS = '{{ $job->status }}';
const JOB_SOURCE = '{{ $job->source }}';
let logsLoaded = false;
let logPage = 0, logTotalPages = 1, logAutoRefresh = null;
let logNextRawLine = 0;  // tracks position for incremental auto-refresh

function switchTab(tab) {
  ['errors', 'error-types', 'logs'].forEach(t => {
    const btn = document.getElementById(`tab-${t}`);
    const pane = document.getElementById(`pane-${t}`);
    if (t === tab) {
      const colors = { errors: 'text-red-400 border-red-500', logs: 'text-blue-400 border-blue-500',
        'error-types': 'text-yellow-400 border-yellow-500' };
      btn.className = 'px-4 py-2 border-b-2 font-semibold ' + (colors[t] || '');
      pane.classList.remove('hidden');
    } else {
      btn.className = 'px-4 py-2 text-gray-500 hover:text-white';
      pane.classList.add('hidden');
    }
  });
  if (tab === 'logs' && !logsLoaded) loadLogs();
}

function _renderLine(line, search) {
  const div = document.createElement('div');
  let cls = 'text-gray-500';
  if (line.includes('[ERROR]'))        cls = 'log-error';
  else if (line.includes('[WARNING]')) cls = 'log-warning';
  else if (line.includes('[INFO]'))    cls = 'log-info';
  else if (line.includes('[DEBUG]'))   cls = 'log-debug';
  if (line.includes('[STAT]'))         cls = 'log-stat';
  div.className = cls;
  if (search) {
    div.innerHTML = line.replace(new RegExp(`(${search.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
      '<mark class="bg-yellow-700/60 text-yellow-200 rounded px-0.5">$1</mark>');
  } else {
    div.textContent = line;
  }
  return div;
}

function loadLogs() {
  const level  = document.getElementById('log-level').value;
  const search = document.getElementById('log-search').value;
  const content = document.getElementById('log-content');
  content.innerHTML = '<div class="text-gray-500">Loading...</div>';
  logNextRawLine = 0;  // full reload resets incremental position

  const params = new URLSearchParams({ level, search, page: logPage, limit: 500 });
  fetch(`/admin/jobs/${JOB_ID}/log?${params}`)
    .then(r => r.json())
    .then(data => {
      logsLoaded = true;
      if (data.error) {
        content.innerHTML = `<div class="text-red-400">${data.error}</div>`;
        return;
      }
      logNextRawLine = data.next_raw_line ?? data.total_raw ?? 0;

      const mb = (data.file_size / 1024 / 1024).toFixed(1);
      document.getElementById('log-meta').textContent =
        `${data.total.toLocaleString()} of ${data.total_raw.toLocaleString()} lines · ${mb} MB`;

      logPage = data.page;
      logTotalPages = data.total_pages;
      const pgEl = document.getElementById('log-pagination');
      if (logTotalPages > 1) {
        pgEl.classList.remove('hidden');
        document.getElementById('log-page-info').textContent = `Page ${logPage + 1} / ${logTotalPages}`;
      } else {
        pgEl.classList.add('hidden');
      }

      if (!data.lines.length) {
        content.innerHTML = '<div class="text-gray-600">No matching log lines</div>';
        return;
      }
      content.innerHTML = '';
      data.lines.forEach(line => content.appendChild(_renderLine(line, search)));
    })
    .catch(e => {
      content.innerHTML = `<div class="text-red-400">Failed: ${e}</div>`;
    });
}

function loadLogsTail() {
  // Incremental: only fetch lines added since last load (no filter, no pagination)
  if (!logsLoaded || logNextRawLine === 0) { loadLogs(); return; }
  const params = new URLSearchParams({ since_raw_line: logNextRawLine, limit: 500 });
  fetch(`/admin/jobs/${JOB_ID}/log?${params}`)
    .then(r => r.json())
    .then(data => {
      if (!data.lines || !data.lines.length) return;
      logNextRawLine = data.next_raw_line ?? logNextRawLine;
      const content = document.getElementById('log-content');
      const atBottom = content.scrollHeight - content.scrollTop - content.clientHeight < 60;
      data.lines.forEach(line => content.appendChild(_renderLine(line, '')));
      if (atBottom) content.scrollTop = content.scrollHeight;
      const mb = (data.file_size / 1024 / 1024).toFixed(1);
      document.getElementById('log-meta').textContent =
        `${data.total_raw.toLocaleString()} lines · ${mb} MB`;
    })
    .catch(() => {});
}

function toggleLogAutoRefresh() {
  if (logAutoRefresh) {
    clearInterval(logAutoRefresh);
    logAutoRefresh = null;
    document.getElementById('log-ar-state').textContent = 'OFF';
    document.getElementById('log-ar-btn').classList.remove('text-green-400');
    document.getElementById('log-ar-btn').classList.add('text-gray-400');
  } else {
    if (!logsLoaded) loadLogs();
    logAutoRefresh = setInterval(() => loadLogsTail(), 3000);
    document.getElementById('log-ar-state').textContent = '3s';
    document.getElementById('log-ar-btn').classList.add('text-green-400');
    document.getElementById('log-ar-btn').classList.remove('text-gray-400');
  }
}

function scrollLogBottom() {
  const el = document.getElementById('log-content');
  el.scrollTop = el.scrollHeight;
}

// Live updates via SSE for running jobs
if (JOB_STATUS === 'running') {
  const fmt = n => typeof n === 'number' ? n.toLocaleString() : n;
  const fmtTime = s => {
    if (!s) return '--';
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    return h ? `${h}h ${m}m` : `${m}m`;
  };

  // lifetimeStart will be synced from SSE elapsed_s on first event
  let lifetimeStart = null;
  setInterval(() => {
    if (lifetimeStart === null) return;
    const el = Math.floor(Date.now() / 1000) - lifetimeStart;
    document.getElementById('t-elapsed').textContent = fmtTime(el);
  }, 1000);

  const es = new EventSource(`/admin/jobs/${JOB_ID}/progress`);
  es.onmessage = (e) => {
    const d = JSON.parse(e.data);

    // Progress bar
    if (d.pct !== undefined) {
      const bar = document.getElementById('pb-bar');
      const lbl = document.getElementById('pb-label');
      const det = document.getElementById('pb-detail');
      if (bar) bar.style.width = d.pct + '%';
      if (lbl) lbl.textContent = d.pct + '%';
      if (det) det.textContent = `${fmt(d.found_total)} / ${fmt(d.api_total)}`;
    }

    // Stats cards
    if (d.total !== undefined) document.getElementById('s-total').textContent = fmt(d.total);
    if (d.pct !== undefined) {
      const cel = document.getElementById('s-coverage');
      cel.textContent = d.pct + '%';
      cel.className = `text-xl font-bold mt-1 ${d.pct >= 95 ? 'text-green-400' : d.pct > 0 ? 'text-yellow-400' : 'text-gray-500'}`;
    }
    if (d.new !== undefined) document.getElementById('s-new').textContent = fmt(d.new);
    if (d.updated !== undefined) document.getElementById('s-updated').textContent = fmt(d.updated);
    if (d.errors !== undefined) {
      const eel = document.getElementById('s-errors');
      eel.textContent = d.errors;
      eel.className = `text-xl font-bold mt-1 ${d.errors > 0 ? 'text-red-400' : 'text-gray-500'}`;
    }

    // Timing cards — sync local timer from server elapsed_s
    if (d.elapsed_s) {
      if (lifetimeStart === null) {
        lifetimeStart = Math.floor(Date.now() / 1000) - d.elapsed_s;
      }
      document.getElementById('t-elapsed').textContent = fmtTime(d.elapsed_s);
    } else if (d.time) document.getElementById('t-elapsed').textContent = d.time;
    if (d.avg_per_lot_s) document.getElementById('t-avg').textContent = d.avg_per_lot_s + 's';
    if (d.search_time_s !== undefined) document.getElementById('t-search').textContent = d.search_time_s + 's';
    if (d.enrich_time_s !== undefined) document.getElementById('t-enrich').textContent = d.enrich_time_s + 's';
    if (d.pause_time_s !== undefined) document.getElementById('t-pause').textContent = d.pause_time_s + 's';

    // API total sub-label
    if (d.api_total) document.querySelector('#s-total + div')?.remove(),
      document.getElementById('s-total').insertAdjacentHTML('afterend', `<div class="text-[10px] text-gray-600 mt-0.5">API: ${fmt(d.api_total)}</div>`);

    // Status change
    if (['done', 'error', 'cancelled'].includes(d.status)) {
      const badge = document.getElementById('status-badge');
      const colors = { done: 'bg-green-900 text-green-400', error: 'bg-red-900 text-red-400', cancelled: 'bg-gray-800 text-gray-500' };
      badge.className = `text-xs px-2 py-0.5 rounded-full ${colors[d.status] ?? ''}`;
      badge.textContent = d.status;
      es.close();
      // Reload to get final data
      setTimeout(() => location.reload(), 1000);
    }
  };
  es.onerror = () => es.close();
}
</script>

@endsection
