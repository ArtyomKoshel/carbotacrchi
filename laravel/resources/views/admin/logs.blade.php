@extends('admin.layout')
@section('title', 'Logs')

@section('content')

{{-- Filter bar --}}
<div class="flex items-center gap-3 mb-4">
  @foreach(['' => 'All', 'ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info'] as $lv => $label)
  <a href="{{ route('admin.logs', array_filter(['level' => $lv])) }}"
     class="px-3 py-1.5 rounded-lg text-sm transition
            {{ $level === $lv ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
    {{ $label }}
  </a>
  @endforeach

  <form method="GET" class="ml-auto">
    <input type="hidden" name="level" value="{{ $level }}">
    <button type="submit"
            class="px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-white transition">
      ↻ Refresh
    </button>
  </form>
</div>

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
