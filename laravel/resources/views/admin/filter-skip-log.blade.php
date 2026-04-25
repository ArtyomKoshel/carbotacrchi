@extends('admin.layout')

@section('title', 'Filter Skip Log')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  {{ session('error') }}
</div>
@endif

{{-- Filter bar --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
  <form method="GET" action="{{ route('admin.filter-skip-log.index') }}">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <div>
        <label class="text-xs text-gray-500 mb-1 block">Source</label>
        <select name="source"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All</option>
          @foreach($sources as $src)
            <option value="{{ $src }}" {{ request('source') == $src ? 'selected' : '' }}>{{ $src }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 mb-1 block">Rule ID</label>
        <input type="number" name="rule_id" value="{{ request('rule_id') }}"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>
      <div>
        <label class="text-xs text-gray-500 mb-1 block">Date From</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>
      <div>
        <label class="text-xs text-gray-500 mb-1 block">Date To</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>
      <div class="flex items-end">
        <button type="submit"
                class="w-full px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
          Filter
        </button>
      </div>
    </div>
  </form>
</div>

{{-- Cleanup --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
  <form method="POST" action="{{ route('admin.filter-skip-log.cleanup') }}" class="flex items-end gap-4">
    @csrf
    <div>
      <label class="text-xs text-gray-500 mb-1 block">Delete entries older than (days)</label>
      <input type="number" name="days" value="30" min="1"
             class="w-32 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
    </div>
    <button type="submit"
            class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-semibold transition">
      Cleanup
    </button>
    <span class="text-xs text-gray-600">Permanently deletes old log entries.</span>
  </form>
</div>

{{-- Log table --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-800/60 text-gray-400 text-xs uppercase tracking-wider">
      <tr>
        <th class="px-4 py-3 text-left">Skipped At</th>
        <th class="px-4 py-3 text-left">Source</th>
        <th class="px-4 py-3 text-left">Source ID</th>
        <th class="px-4 py-3 text-left">Rule</th>
        <th class="px-4 py-3 text-left">Action</th>
        <th class="px-4 py-3 text-left">Field</th>
        <th class="px-4 py-3 text-left">Value</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
      @forelse($logs as $log)
        <tr class="hover:bg-gray-800/40 transition">
          <td class="px-4 py-2 text-gray-400 whitespace-nowrap">{{ $log->skipped_at->format('Y-m-d H:i') }}</td>
          <td class="px-4 py-2">
            <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold
              {{ $log->source === 'encar' ? 'bg-blue-900/60 text-blue-300' : 'bg-green-900/60 text-green-300' }}">
              {{ $log->source }}
            </span>
          </td>
          <td class="px-4 py-2">
            <a href="{{ $log->lot_url }}" target="_blank"
               class="text-blue-400 hover:text-blue-300 hover:underline">{{ $log->source_id }}</a>
          </td>
          <td class="px-4 py-2 text-gray-300">{{ $log->rule_name }}</td>
          <td class="px-4 py-2">
            <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold
              {{ $log->action === 'skip' ? 'bg-red-900/60 text-red-300' : 'bg-yellow-900/60 text-yellow-300' }}">
              {{ $log->action }}
            </span>
          </td>
          <td class="px-4 py-2 text-gray-400">{{ $log->field_name }}</td>
          <td class="px-4 py-2 text-gray-500 truncate max-w-[200px]">{{ $log->field_value }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="px-4 py-8 text-center text-gray-600">No log entries found</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  @if($logs->hasPages())
    <div class="px-4 py-3 border-t border-gray-800">
      {{ $logs->appends(request()->query())->links('pagination::tailwind') }}
    </div>
  @endif
</div>

@endsection
