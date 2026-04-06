@extends('admin.layout')
@section('title', 'Schedules')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

<p class="text-sm text-gray-500 mb-6">
  Changes take effect after the parser process restarts. Schedule format: <code class="text-gray-400">interval:60</code> or <code class="text-gray-400">cron:0 * * * *</code>
</p>

<div class="space-y-4">
  @foreach($sources as $source)
  @php $s = $schedules[$source] ?? null @endphp
  <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800">
      <span class="font-semibold text-white">{{ $source }}</span>
      <span class="text-xs px-2 py-0.5 rounded-full {{ $s?->enabled ? 'bg-green-900 text-green-400' : 'bg-gray-800 text-gray-500' }}">
        {{ $s?->enabled ? 'enabled' : 'disabled' }}
      </span>
    </div>
    <form method="POST"
          action="{{ route('admin.schedules.update', ['source' => $source, 'token' => request()->query('token')]) }}"
          class="px-5 py-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 items-end">
      @csrf

      <div class="flex flex-col gap-1">
        <label class="text-xs text-gray-500">Enabled</label>
        <select name="enabled" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="1" {{ $s?->enabled ? 'selected' : '' }}>Yes</option>
          <option value="0" {{ ($s && !$s->enabled) ? 'selected' : '' }}>No</option>
        </select>
      </div>

      <div class="flex flex-col gap-1">
        <label class="text-xs text-gray-500">Schedule</label>
        <input type="text" name="schedule" value="{{ $s?->schedule }}"
               placeholder="interval:60"
               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600">
      </div>

      <div class="flex flex-col gap-1">
        <label class="text-xs text-gray-500">Interval (min)</label>
        <input type="number" name="interval_minutes" min="1"
               value="{{ $s?->interval_minutes ?? 60 }}"
               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>

      <div class="flex flex-col gap-1">
        <label class="text-xs text-gray-500">Max pages <span class="text-gray-600">(0=all)</span></label>
        <input type="number" name="max_pages" min="0"
               value="{{ $s?->max_pages ?? 0 }}"
               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>

      <div class="flex flex-col gap-1">
        <label class="text-xs text-gray-500">Maker filter</label>
        <input type="text" name="maker_filter" value="{{ $s?->maker_filter }}"
               placeholder="optional"
               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600">
      </div>

      <div class="col-span-full flex items-center gap-3">
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
          Save
        </button>
        @if($s)
        <span class="text-xs text-gray-600">Last updated: {{ $s->updated_at->diffForHumans() }}</span>
        @endif
      </div>
    </form>
  </div>
  @endforeach
</div>

@endsection
