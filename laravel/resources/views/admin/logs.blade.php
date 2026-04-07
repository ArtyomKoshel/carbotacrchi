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
  <span class="ml-auto text-xs text-gray-600">{{ config('admin.log_file') }}</span>
</div>

{{-- Filter form (GET) --}}
<form method="GET" class="mb-4 space-y-2">
  {{-- Level filter --}}
  <div class="flex items-center gap-2 flex-wrap">
    @foreach(['' => 'All', 'ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info', 'DEBUG' => 'Debug'] as $lv => $label)
    <button type="submit" name="level" value="{{ $lv }}"
            class="px-3 py-1.5 rounded-lg text-sm transition
                   {{ $level === $lv ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
      {{ $label }}
    </button>
    @endforeach
    <a href="{{ route('admin.logs', array_merge(array_filter(['level' => $level, 'source' => $source]), ['search' => '[STAT]'])) }}"
       class="px-3 py-1.5 rounded-lg text-sm transition
              {{ $search === '[STAT]' ? 'bg-cyan-700 text-white' : 'bg-gray-800 text-cyan-500 hover:bg-cyan-900/40' }}">
      📊 Stats
    </a>
    <input type="hidden" name="search" value="{{ $search }}">
    <input type="hidden" name="source" value="{{ $source }}">
  </div>

  {{-- Source quick-filter buttons --}}
  <div class="flex items-center gap-2 flex-wrap">
    <span class="text-xs text-gray-600">Parser:</span>
    @foreach(['' => 'All', 'kbcha' => 'KBCha', 'encar' => 'Encar'] as $src => $label)
    <button type="submit" name="source" value="{{ $src }}"
            class="px-3 py-1.5 rounded-lg text-sm transition
                   {{ $source === $src ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
      {{ $label }}
    </button>
    @endforeach
    <input type="hidden" name="level" value="{{ $level }}">
    <input type="hidden" name="search" value="{{ $search }}">
  </div>

  {{-- Search text + manual source --}}
  <div class="flex gap-2">
    <input type="text" name="search" value="{{ $search }}" placeholder="Search text..."
           class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
    <input type="hidden" name="level" value="{{ $level }}">
    <input type="hidden" name="source" value="{{ $source }}">
    <button type="submit"
            class="px-4 py-1.5 rounded-lg text-sm bg-blue-700 hover:bg-blue-600 text-white transition">
      Search
    </button>
    @if($search || $source || $level)
    <a href="{{ route('admin.logs') }}"
       class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-500 hover:text-red-400 transition">✕ Reset</a>
    @endif
  </div>
</form>

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
    <div class="px-4 py-2 border-b border-gray-800 text-xs text-gray-600">
      Showing {{ count($lines) }} lines
      @if($level) · level: <span class="text-gray-400">{{ $level }}</span>@endif
      @if($source) · parser: <span class="text-gray-400">{{ $source }}</span>@endif
      @if($search) · search: <span class="text-gray-400">"{{ $search }}"</span>@endif
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
