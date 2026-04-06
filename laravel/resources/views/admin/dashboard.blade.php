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
    <div class="text-xs text-gray-600 mt-2">Last lot update: {{ \Carbon\Carbon::parse($lastParsed[$src->source])->diffForHumans() }}</div>
    @endif
    @if(isset($lastScheduled[$src->source]))
    @php $sched = $lastScheduled[$src->source]; @endphp
    <div class="text-xs mt-1 flex items-center gap-1.5">
      <span class="text-gray-600">⏱ Scheduler:</span>
      <span class="text-gray-500">{{ \Carbon\Carbon::parse($sched->last_run)->diffForHumans() }}</span>
      @php
        $sc = match($sched->last_status ?? '') {
          'done'    => 'text-green-500',
          'error'   => 'text-red-500',
          'running' => 'text-yellow-500',
          default   => 'text-blue-500',
        };
      @endphp
      <span class="text-xs {{ $sc }}">· {{ $sched->last_status }}</span>
    </div>
    @else
    <div class="text-xs text-gray-700 mt-1">⏱ Scheduler: never ran</div>
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

{{-- Proxy balance --}}
@if($proxyBalance)
@php
  $res = $proxyBalance['residential'] ?? null;
  $gb  = ($res['nonExpiring']['gb'] ?? 0) + ($res['subscription']['gb'] ?? 0);
  $pct = min(100, round($gb / 9 * 100));
  $color = $pct > 50 ? 'bg-green-500' : ($pct > 20 ? 'bg-yellow-500' : 'bg-red-500');
@endphp
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-8">
  <div class="flex items-center justify-between mb-3">
    <span class="text-xs font-bold uppercase tracking-widest text-gray-500">Proxy Traffic (Floppydata)</span>
    <span class="text-xs text-gray-500">Residential</span>
  </div>
  <div class="flex items-end gap-3 mb-3">
    <span class="text-3xl font-bold text-white">{{ number_format($gb, 2) }} GB</span>
    <span class="text-sm text-gray-500 mb-1">remaining</span>
  </div>
  <div class="w-full bg-gray-800 rounded-full h-2">
    <div class="{{ $color }} h-2 rounded-full transition-all" style="width: {{ $pct }}%"></div>
  </div>
  @if(isset($res['subscription']['expiresOn']))
  <div class="text-xs text-gray-600 mt-2">
    Subscription expires: {{ \Carbon\Carbon::parse($res['subscription']['expiresOn'])->diffForHumans() }}
  </div>
  @endif
</div>
@endif

{{-- Recent changes --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
    <span class="font-semibold text-white">Recent Changes</span>
    <a href="{{ route('admin.changes') }}"
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
          @php
            $fv = fn($v) => is_null($v) ? '—' : (is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v) : $v));
          @endphp
          @foreach($ch->changes as $field => $diff)
            @if($field === 'is_active' && count($ch->changes) === 1)
              {{-- delisted/relisted: is_active alone is obvious from the badge --}}
              @continue
            @endif
            <span class="mr-2">
              <span class="text-gray-500">{{ $field }}:</span>
              @if(isset($diff['old']))
                <span class="line-through text-gray-600 ml-1">{{ $fv($diff['old']) }}</span>
                <span class="text-blue-400 ml-1">→ {{ $fv($diff['new']) }}</span>
              @elseif(isset($diff['new']))
                <span class="text-green-400 ml-1">{{ $fv($diff['new']) }}</span>
              @endif
            </span>
          @endforeach
          @if(in_array($ch->event, ['delisted','relisted']) && count($ch->changes) <= 1)
            <span class="text-gray-600 italic">{{ $ch->event }}</span>
          @endif
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
