@extends('admin.layout')
@section('title', 'Logs')

@section('content')

{{-- Action bar — outside any form to avoid nested-form bug --}}
<div class="flex items-center gap-2 flex-wrap mb-3">
  <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source])) }}"
     class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-white transition">
    ↻ Refresh
  </a>
  <button id="auto-refresh-btn" onclick="toggleAutoRefresh()"
          class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-green-400 transition">
    ⏱ Auto-refresh: <span id="ar-state">OFF</span>
  </button>
  <a href="{{ route('admin.logs.download', array_filter(['level' => $level, 'search' => $search, 'source' => $source])) }}"
     class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-green-400 transition">
    ↓ Download
  </a>
  <form method="POST" action="{{ route('admin.logs.clear') }}" class="inline"
        onsubmit="return confirm('Clear the entire log file?')">
    @csrf
    <button type="submit"
            class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-red-400 transition">
      🗑 Clear log
    </button>
  </form>
  <span class="text-xs text-gray-600 ml-auto mr-1">Lines:</span>
  @foreach([500, 1000, 3000, 10000] as $lim)
  <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'file' => $fileIdx ?: null, 'job' => $jobFile ?: null, 'limit' => $lim])) }}"
     class="px-2 py-1 rounded text-xs transition
            {{ $maxLines == $lim ? 'bg-gray-600 text-white' : 'bg-gray-800 text-gray-500 hover:text-white' }}">
    {{ number_format($lim) }}
  </a>
  @endforeach
  <span class="text-xs text-gray-600 ml-2">{{ $jobFile ?: basename(config('admin.log_file')) . ($fileIdx > 0 ? '.'.$fileIdx : '') }}</span>
</div>

{{-- Rotation file selector (only shown when backup files exist) --}}
@if(count($rotationFiles) > 1)
<div class="flex items-center gap-2 flex-wrap mb-3">
  <span class="text-xs text-gray-600">File:</span>
  @foreach($rotationFiles as $rf)
  <a href="{{ route('admin.logs', array_filter(['level' => $level, 'search' => $search, 'source' => $source, 'file' => $rf['idx'] ?: null])) }}"
     class="px-3 py-1.5 rounded-lg text-xs font-mono transition
            {{ !$jobFile && $fileIdx === $rf['idx'] ? 'bg-gray-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
    {{ $rf['label'] }}{{ $rf['idx'] === 0 ? ' (current)' : '' }}
  </a>
  @endforeach
</div>
@endif

{{-- Filters --}}
<div class="mb-4 space-y-2">
  {{-- Level filter (links, not form buttons) --}}
  <div class="flex items-center gap-2 flex-wrap">
    @foreach(['' => 'All', 'ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info', 'DEBUG' => 'Debug'] as $lv => $lbl)
    <a href="{{ route('admin.logs', array_filter(['level' => $lv, 'search' => $search, 'source' => $source, 'job' => $jobFile ?: null, 'limit' => $maxLines != 1000 ? $maxLines : null])) }}"
       class="px-3 py-1.5 rounded-lg text-sm transition
              {{ $level === $lv ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
      {{ $lbl }}
    </a>
    @endforeach
    <a href="{{ route('admin.logs', array_filter(['level' => $level, 'source' => $source, 'search' => '[STAT]', 'job' => $jobFile ?: null, 'limit' => $maxLines != 1000 ? $maxLines : null])) }}"
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
    @if($maxLines != 1000)<input type="hidden" name="limit" value="{{ $maxLines }}">@endif
    <input type="text" name="search" value="{{ $search }}" placeholder="Search text..."
           class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
    <button type="submit"
            class="px-4 py-1.5 rounded-lg text-sm bg-blue-700 hover:bg-blue-600 text-white transition">
      Search
    </button>
    @if($search || $source || $level)
    <a href="{{ route('admin.logs', array_filter(['job' => $jobFile ?: null])) }}"
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
        $pq = array_filter(['level'=>$level,'search'=>$search,'source'=>$source,'file'=>$fileIdx?:null,'job'=>$jobFile?:null,'limit'=>$maxLines!=1000?$maxLines:null]);
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

<script>
let _arTimer = null;
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
    fetch(window.location.href)
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newPre = doc.getElementById('log-pre');
            const curPre = document.getElementById('log-pre');
            if (newPre && curPre) {
                const atBottom = curPre.scrollHeight - curPre.scrollTop <= curPre.clientHeight + 40;
                curPre.innerHTML = newPre.innerHTML;
                if (atBottom) curPre.scrollTop = curPre.scrollHeight;
            }
        })
        .catch(() => {});
}
</script>

@endsection
