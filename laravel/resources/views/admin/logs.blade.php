@extends('admin.layout')
@section('title', 'Logs')

@section('content')

{{-- Filter bar --}}
<form method="GET" class="mb-4 space-y-3">
  <div class="flex items-center gap-2 flex-wrap">
    @foreach(['' => 'All', 'ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info', 'DEBUG' => 'Debug'] as $lv => $label)
    <button type="submit" name="level" value="{{ $lv }}"
            class="px-3 py-1.5 rounded-lg text-sm transition
                   {{ $level === $lv ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
      {{ $label }}
    </button>
    @endforeach

    <input type="hidden" name="search" value="{{ $search }}">
    <input type="hidden" name="source" value="{{ $source }}">

    <a href="{{ route('admin.logs.download', array_filter(['level' => $level, 'search' => $search])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-green-400 transition">
      ↓ Download
    </a>
    <button type="submit"
            class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-white transition">
      ↻ Refresh
    </button>
  </div>

  <div class="flex gap-2">
    <input type="text" name="search" value="{{ $search }}" placeholder="Search text..."
           class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
    <input type="text" name="source" value="{{ $source }}" placeholder="Source (e.g. kbcha)"
           class="w-40 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
    @if($search || $source)
    <a href="{{ route('admin.logs', array_filter(['level' => $level])) }}"
       class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-500 hover:text-red-400 transition">✕ Clear</a>
    @endif
  </div>
</form>

@if($error)
  <div class="bg-red-900/30 border border-red-800 rounded-xl px-5 py-4 text-red-400 text-sm">
    {{ $error }}
  </div>
@else
  <div class="bg-gray-950 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-4 py-2 border-b border-gray-800 text-xs text-gray-600">
      Showing last {{ count($lines) }} lines · {{ config('admin.log_file') }}
    </div>
    <pre class="p-4 text-xs leading-5 overflow-x-auto max-h-[72vh] overflow-y-auto font-mono"
         id="log-pre"
    >@foreach($lines as $line)
@php
  $cls = 'log-info';
  if (str_contains($line, '[ERROR]'))   $cls = 'log-error';
  elseif (str_contains($line, '[WARNING]')) $cls = 'log-warning';
  elseif (str_contains($line, '[DEBUG]'))  $cls = 'log-debug';
@endphp
<span class="{{ $cls }}">{{ $line }}</span>
@endforeach</pre>
  </div>
@endif

@endsection
