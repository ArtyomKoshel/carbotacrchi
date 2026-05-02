@extends('admin.layout')
@section('title', 'Logs')

@section('content')

<div id="logs-root" class="flex gap-4" data-file-size="{{ (int)($fileSize ?? 0) }}">

{{-- ── Left sidebar: file picker ─────────────────────────────────────── --}}
<div class="w-56 flex-shrink-0 space-y-3">

  {{-- Main log files --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
    <div class="text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">Parser Logs</div>
    @foreach($rotationFiles as $rf)
    <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'file' => $rf['idx'] ?: null])) }}"
       class="block px-2 py-1.5 rounded text-xs font-mono truncate transition mb-0.5
              {{ !$jobFile && !$appLog && $fileIdx === $rf['idx'] ? 'bg-blue-700/30 text-blue-300 border border-blue-700/50' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
      {{ $rf['label'] }}{{ $rf['idx'] === 0 ? ' (current)' : '' }}
    </a>
    @endforeach
  </div>

  {{-- Laravel app logs (dynamic scan) --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
    <div class="flex items-center justify-between mb-2">
      <span class="text-xs text-gray-500 font-semibold uppercase tracking-wider">App Log</span>
      @if(!empty($appLogFiles))
      <span class="text-xs text-gray-600">{{ count($appLogFiles) }}</span>
      @endif
    </div>
    @forelse($appLogFiles ?? [] as $alf)
    @php
      $isActive = $appLog && basename($appLogPath ?? '') === $alf['label'];
      $sizeStr  = $alf['size'] >= 1048576
        ? round($alf['size'] / 1048576, 1) . ' MB'
        : round($alf['size'] / 1024) . ' KB';
    @endphp
    <a href="{{ route('admin.logs', array_filter(['app' => 1, 'appfile' => $alf['label'], 'level' => $level, 'search' => $search])) }}"
       class="block px-2 py-1.5 rounded text-xs font-mono truncate transition mb-0.5
              {{ $isActive ? 'bg-blue-700/30 text-blue-300 border border-blue-700/50' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
      {{ $alf['label'] }}
      <span class="text-gray-600 ml-1">{{ $sizeStr }}</span>
    </a>
    @empty
    <div class="text-xs text-gray-600">Нет файлов логов</div>
    @endforelse
  </div>

  {{-- Job log files (10D) --}}
  @if(count($jobFiles))
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
    <div class="flex items-center justify-between mb-2">
      <span class="text-xs text-gray-500 font-semibold uppercase tracking-wider">Job Logs</span>
      <span class="text-xs text-gray-600">{{ count($jobFiles) }}</span>
    </div>
    <div class="max-h-64 overflow-y-auto space-y-0.5">
      @foreach($jobFiles as $jf)
      @php
        $isActive = $jobFile === $jf['label'];
        $sizeMb = round($jf['size'] / 1024 / 1024, 1);
        $sizeStr = $sizeMb >= 1 ? $sizeMb . ' MB' : round($jf['size'] / 1024) . ' KB';
        // Extract job ID for linking
        preg_match('/job-(\d+)/', $jf['label'], $m);
        $jid = $m[1] ?? null;
      @endphp
      <div class="flex items-center gap-1 group">
        <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'job' => $jf['label']])) }}"
           class="flex-1 px-2 py-1.5 rounded text-xs font-mono truncate transition
                  {{ $isActive ? 'bg-blue-700/30 text-blue-300 border border-blue-700/50' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
          {{ $jf['label'] }}
          <span class="text-gray-600 ml-1">{{ $sizeStr }}</span>
          <span class="block text-[10px] text-gray-700">{{ \Carbon\Carbon::createFromTimestamp($jf['mtime'])->diffForHumans() }}</span>
        </a>
        @if($jid)
        <a href="{{ route('admin.jobs.detail', $jid) }}" title="View job #{{ $jid }}"
           class="text-gray-600 hover:text-blue-400 text-xs opacity-0 group-hover:opacity-100 transition">→</a>
        @endif
      </div>
      @endforeach
    </div>
    {{-- Clear job logs (10H) --}}
    <form method="POST" action="{{ route('admin.logs.clear.jobs') }}" class="mt-2"
          onsubmit="return confirm('Delete ALL job log files ({{ count($jobFiles) }} files)?')">
      @csrf
      <button type="submit"
              class="w-full px-2 py-1 rounded text-xs bg-gray-800 text-gray-500 hover:text-red-400 hover:bg-red-900/20 transition">
        🗑 Clear all job logs
      </button>
    </form>
  </div>
  @endif

</div>

{{-- ── Main content area ─────────────────────────────────────────────── --}}
<div class="flex-1 min-w-0">

{{-- Action bar --}}
<div class="flex items-center gap-2 flex-wrap mb-3">
  <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'job' => $jobFile ?: null, 'app' => $appLog ? 1 : null])) }}"
     class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-white transition">
    ↻ Refresh
  </a>
  <button id="auto-refresh-btn" onclick="toggleAutoRefresh()"
          class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-green-400 transition">
    ⏱ Auto: <span id="ar-state">OFF</span>
  </button>
  <a href="{{ route('admin.logs.download', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'file' => $fileIdx ?: null, 'job' => $jobFile ?: null, 'app' => $appLog ? 1 : null])) }}"
     class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-green-400 transition">
    ↓ Download
  </a>
  @if(!$jobFile && !$appLog)
  <form method="POST" action="{{ route('admin.logs.clear') }}" class="inline"
        onsubmit="return confirm('Clear the main parser log?')">
    @csrf
    <button type="submit"
            class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-red-400 transition">
      🗑 Clear
    </button>
  </form>
  @endif

  {{-- Source filter (10E) --}}
  <select id="source-select" onchange="applySource(this.value)"
          class="px-2 py-1 rounded-lg text-xs bg-gray-800 border border-gray-700 text-gray-300">
    <option value="" {{ !$source ? 'selected' : '' }}>All sources</option>
    <option value="encar" {{ $source === 'encar' ? 'selected' : '' }}>Encar</option>
    <option value="kbcha" {{ $source === 'kbcha' ? 'selected' : '' }}>KBCha</option>
  </select>

  <span class="text-xs text-gray-600 ml-auto mr-1">Lines:</span>
  @foreach([500, 1000, 3000, 10000] as $lim)
  <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'file' => $fileIdx ?: null, 'job' => $jobFile ?: null, 'app' => $appLog ? 1 : null, 'limit' => $lim])) }}"
     class="px-2 py-1 rounded text-xs transition
            {{ $maxLines == $lim ? 'bg-gray-600 text-white' : 'bg-gray-800 text-gray-500 hover:text-white' }}">
    {{ number_format($lim) }}
  </a>
  @endforeach
  <span class="text-xs text-gray-600 ml-2 font-mono">{{ $appLog ? 'laravel.log' : ($jobFile ?: basename(config('admin.log_file')) . ($fileIdx > 0 ? '.'.$fileIdx : '')) }}</span>
</div>

{{-- Filters --}}
<div class="mb-4 space-y-2">
  {{-- Level filter --}}
  <div class="flex items-center gap-2 flex-wrap">
    @foreach(['' => 'All', 'ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info', 'DEBUG' => 'Debug'] as $lv => $lbl)
    <a href="{{ route('admin.logs', array_filter(['level' => $lv, 'search' => $search, 'source' => $source, 'job' => $jobFile ?: null, 'app' => $appLog ? 1 : null, 'limit' => $maxLines != 1000 ? $maxLines : null])) }}"
       class="px-3 py-1.5 rounded-lg text-sm transition
              {{ $level === $lv ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
      {{ $lbl }}
    </a>
    @endforeach
    <a href="{{ route('admin.logs', array_filter(['level' => $level, 'source' => $source, 'search' => '[STAT]', 'job' => $jobFile ?: null, 'app' => $appLog ? 1 : null, 'limit' => $maxLines != 1000 ? $maxLines : null])) }}"
       class="px-3 py-1.5 rounded-lg text-sm transition
              {{ $search === '[STAT]' ? 'bg-cyan-700 text-white' : 'bg-gray-800 text-cyan-500 hover:bg-cyan-900/40' }}">
      📊 Stats
    </a>
  </div>

  {{-- Search text --}}
  <form method="GET" class="flex gap-2">
    <input type="hidden" name="level"  value="{{ $level }}">
    <input type="hidden" name="source" value="{{ $source }}">
    @if($jobFile)<input type="hidden" name="job" value="{{ $jobFile }}">@endif
    @if($appLog)<input type="hidden" name="app" value="1">@endif
    @if($maxLines != 1000)<input type="hidden" name="limit" value="{{ $maxLines }}">@endif
    <input type="text" name="search" value="{{ $search }}" placeholder="Search text..."
           class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
    <button type="submit"
            class="px-4 py-1.5 rounded-lg text-sm bg-blue-700 hover:bg-blue-600 text-white transition">
      Search
    </button>
    @if($search || $source || $level)
    <a href="{{ route('admin.logs', array_filter(['job' => $jobFile ?: null, 'app' => $appLog ? 1 : null])) }}"
       class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-500 hover:text-red-400 transition">✕ Reset</a>
    @endif
  </form>
</div>

@if(session('success'))
  <div class="bg-green-900/30 border border-green-800 rounded-xl px-5 py-3 text-green-400 text-sm mb-3">
    {{ session('success') }}
  </div>
@endif
@if(session('error'))
  <div class="bg-red-900/30 border border-red-800 rounded-xl px-5 py-3 text-red-400 text-sm mb-3">
    {{ session('error') }}
  </div>
@endif

@if($error)
  <div class="bg-red-900/30 border border-red-800 rounded-xl px-5 py-4 text-red-400 text-sm">
    {{ $error }}
  </div>
@else
  <div class="bg-gray-950 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-4 py-2 border-b border-gray-800 flex items-center gap-3 flex-wrap">
      <span class="text-xs text-gray-600">
        ~{{ number_format($totalLines) }} lines · page {{ $page + 1 }} / {{ $totalPages }}
        @if($level) · level: <span class="text-gray-400">{{ $level }}</span>@endif
        @if($source) · parser: <span class="text-gray-400">{{ $source }}</span>@endif
        @if($search) · search: <span class="text-gray-400">"{{ $search }}"</span>@endif
      </span>
      @if($totalPages > 1)
      @php
        $pq = array_filter(['level'=>$level,'search'=>$search,'source'=>$source,'file'=>$fileIdx?:null,'job'=>$jobFile?:null,'app'=>$appLog?1:null,'limit'=>$maxLines!=1000?$maxLines:null]);
      @endphp
      <div class="ml-auto flex items-center gap-1">
        @if($page > 0)
        <a href="{{ route('admin.logs', array_merge($pq, ['page' => 0])) }}"
           class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">«</a>
        <a href="{{ route('admin.logs', array_merge($pq, ['page' => $page - 1])) }}"
           class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">‹ Prev</a>
        @endif
        @php $start = max(0, $page - 2); $end = min($totalPages - 1, $page + 2); @endphp
        @for($p = $start; $p <= $end; $p++)
        <a href="{{ route('admin.logs', array_merge($pq, ['page' => $p])) }}"
           class="px-2 py-0.5 rounded text-xs {{ $p === $page ? 'bg-blue-700 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
          {{ $p + 1 }}
        </a>
        @endfor
        @if($page < $totalPages - 1)
        <a href="{{ route('admin.logs', array_merge($pq, ['page' => $page + 1])) }}"
           class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">Next ›</a>
        <a href="{{ route('admin.logs', array_merge($pq, ['page' => $totalPages - 1])) }}"
           class="px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 hover:text-white">»</a>
        @endif
      </div>
      @endif
    </div>
    <pre class="p-4 text-xs leading-5 overflow-x-auto max-h-[72vh] overflow-y-auto font-mono"
         id="log-pre"
    >@foreach($lines as $line)
@php
  $cls = 'log-info';
  if (str_contains($line, '[ERROR]'))      $cls = 'log-error';
  elseif (str_contains($line, '[WARNING]')) $cls = 'log-warning';
  elseif (str_contains($line, '[DEBUG]'))  $cls = 'log-debug';
  elseif (str_contains($line, '[STAT]'))   $cls = 'log-stat';
@endphp
<span class="{{ $cls }}">{{ $line }}</span>
@endforeach</pre>
  </div>
@endif

</div>{{-- end main content --}}
</div>{{-- end flex layout --}}

<script>
let _arTimer = null;
let _sinceByte = Number((document.getElementById('logs-root')?.dataset.fileSize) || 0);

function _lineClass(line) {
    if (line.includes('[STAT]')) return 'log-stat';
    if (line.includes('[ERROR]')) return 'log-error';
    if (line.includes('[WARNING]')) return 'log-warning';
    if (line.includes('[DEBUG]')) return 'log-debug';
    return 'log-info';
}

function _appendLines(lines) {
    if (!lines || !lines.length) return;
    const pre = document.getElementById('log-pre');
    if (!pre) return;
    const atBottom = pre.scrollHeight - pre.scrollTop <= pre.clientHeight + 40;

    lines.forEach((line) => {
        const span = document.createElement('span');
        span.className = _lineClass(line);
        span.textContent = line;
        pre.appendChild(span);
        pre.appendChild(document.createTextNode('\n'));
    });

    if (atBottom) pre.scrollTop = pre.scrollHeight;
}

function toggleAutoRefresh() {
    const btn = document.getElementById('ar-state');
    if (_arTimer) {
        clearInterval(_arTimer);
        _arTimer = null;
        btn.textContent = 'OFF';
        btn.parentElement.classList.remove('text-green-400');
        btn.parentElement.classList.add('text-gray-400');
    } else {
        btn.textContent = 'ON';
        btn.parentElement.classList.remove('text-gray-400');
        btn.parentElement.classList.add('text-green-400');
        _arTimer = setInterval(refreshLogs, 5000);
    }
}
function refreshLogs() {
    const params = new URLSearchParams(window.location.search);
    params.set('since_byte', String(_sinceByte));
    params.set('limit', '1500');
    fetch(`/admin/logs/tail?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            _appendLines(data.lines || []);
            if (typeof data.next_byte === 'number') {
                _sinceByte = data.next_byte;
            }
        })
        .catch(() => {});
}
function applySource(val) {
    const url = new URL(window.location.href);
    if (val) url.searchParams.set('source', val);
    else url.searchParams.delete('source');
    url.searchParams.delete('page');
    window.location.href = url.toString();
}
</script>

@endsection
