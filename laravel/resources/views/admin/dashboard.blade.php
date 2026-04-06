@extends('admin.layout')
@section('title', 'Dashboard')

@section('content')

{{-- Source stats --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
  @foreach($sources as $src)
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="flex items-center justify-between mb-3">
      <span class="text-xs font-bold uppercase tracking-widest text-gray-500">{{ $src->source }}</span>
      <span class="text-xs px-2 py-0.5 rounded-full bg-green-900 text-green-400">active</span>
    </div>
    <div class="text-3xl font-bold text-white">{{ number_format($src->active) }}</div>
    <div class="text-sm text-gray-500 mt-1">{{ number_format($src->total) }} total &nbsp;·&nbsp; {{ number_format($src->total - $src->active) }} inactive</div>
    @if(isset($lastParsed[$src->source]))
    <div class="text-xs text-gray-600 mt-2">Last parsed: {{ \Carbon\Carbon::parse($lastParsed[$src->source])->diffForHumans() }}</div>
    @endif
  </div>
  @endforeach
</div>

{{-- Last 24h change summary --}}
<div class="grid grid-cols-3 gap-4 mb-8">
  @foreach(['update' => 'blue', 'delisted' => 'red', 'relisted' => 'green'] as $ev => $color)
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">{{ $ev }} (24h)</div>
    <div class="text-2xl font-bold text-{{ $color }}-400">{{ $changeSummary[$ev] ?? 0 }}</div>
  </div>
  @endforeach
</div>

{{-- Recent changes --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
    <span class="font-semibold text-white">Recent Changes</span>
    <a href="{{ route('admin.changes', ['token' => request()->query('token')]) }}"
       class="text-xs text-blue-400 hover:text-blue-300">View all →</a>
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left">Lot</th>
        <th class="px-5 py-3 text-left">Event</th>
        <th class="px-5 py-3 text-left">Changes</th>
        <th class="px-5 py-3 text-left">When</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
      @forelse($recentChanges as $ch)
      <tr class="hover:bg-gray-800/50">
        <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $ch->lot_id }}</td>
        <td class="px-5 py-3">
          @php
            $badge = match($ch->event) {
              'delisted' => 'bg-red-900 text-red-400',
              'relisted' => 'bg-green-900 text-green-400',
              default    => 'bg-blue-900 text-blue-400',
            };
          @endphp
          <span class="text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $ch->event }}</span>
        </td>
        <td class="px-5 py-3 text-gray-300 text-xs">
          @foreach($ch->changes as $field => $diff)
            <span class="mr-2 text-gray-500">{{ $field }}:</span>
            @if(isset($diff['old']))
              <span class="line-through text-gray-600">{{ is_array($diff['old']) ? json_encode($diff['old']) : $diff['old'] }}</span>
              <span class="text-green-400 ml-1">{{ is_array($diff['new']) ? json_encode($diff['new']) : $diff['new'] }}</span>
            @endif
          @endforeach
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap">
          {{ \Carbon\Carbon::parse($ch->recorded_at)->diffForHumans() }}
        </td>
      </tr>
      @empty
      <tr><td colspan="4" class="px-5 py-8 text-center text-gray-600">No changes yet</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

@endsection
