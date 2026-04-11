@extends('admin.layout')
@section('title', 'Changes')

@section('content')

{{-- Charts row --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="text-sm font-semibold text-white mb-4">Changes per day (14 days)</div>
    @php $maxCnt = $dailyChanges->max('cnt') ?: 1; @endphp
    <div class="flex items-end gap-1 h-28">
      @foreach($dailyChanges as $day)
      <div class="flex-1 group relative">
        <div class="w-full bg-blue-600 rounded-t hover:bg-blue-500 transition"
             style="height: {{ round(($day->cnt / $maxCnt) * 100) }}%"
             title="{{ $day->day }}: {{ $day->cnt }}"></div>
      </div>
      @endforeach
    </div>
    <div class="flex justify-between text-xs text-gray-600 mt-2">
      <span>{{ $dailyChanges->first()?->day ?? '' }}</span>
      <span>{{ $dailyChanges->last()?->day ?? '' }}</span>
    </div>
  </div>

  <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-800">
      <span class="font-semibold text-white text-sm">Most changed lots (7 days)</span>
    </div>
    <div class="max-h-[180px] overflow-y-auto">
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-800">
          @forelse($topChanged as $row)
          <tr class="hover:bg-gray-800/50">
            <td class="px-5 py-2 font-mono text-xs text-gray-400">{{ $row->lot_id }}</td>
            <td class="px-5 py-2 text-right">
              <span class="text-xs px-2 py-0.5 rounded-full bg-blue-900 text-blue-400">{{ $row->cnt }}</span>
            </td>
          </tr>
          @empty
          <tr><td colspan="2" class="px-5 py-6 text-center text-gray-600">No data</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

{{-- Filter bar --}}
<div class="flex gap-2 mb-6">
  @foreach(['', 'update', 'delisted', 'relisted'] as $ev)
  <a href="{{ route('admin.changes', array_filter(['event' => $ev])) }}"
     class="px-3 py-1.5 rounded-lg text-sm transition
            {{ $event === $ev ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' }}">
    {{ $ev ?: 'All' }}
  </a>
  @endforeach
</div>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left">Lot ID</th>
        <th class="px-5 py-3 text-left">Source</th>
        <th class="px-5 py-3 text-left">Event</th>
        <th class="px-5 py-3 text-left">Fields changed</th>
        <th class="px-5 py-3 text-left">When</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
      @forelse($changes as $ch)
      <tr class="hover:bg-gray-800/50">
        <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $ch->lot_id }}</td>
        <td class="px-5 py-3 text-gray-400 text-xs">{{ $ch->source }}</td>
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
        <td class="px-5 py-3 text-xs space-y-0.5">
          @php
            $fv = fn($v) => is_null($v) ? '—' : (is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v) : $v));
          @endphp
          @foreach($ch->changes as $field => $diff)
            <div>
              <span class="text-gray-500">{{ $field }}:</span>
              @if(isset($diff['old']))
                <span class="line-through text-gray-600 ml-1">{{ $fv($diff['old']) }}</span>
                <span class="{{ $ch->event === 'delisted' ? 'text-red-400' : ($ch->event === 'relisted' ? 'text-green-400' : 'text-blue-400') }} ml-1">→ {{ $fv($diff['new']) }}</span>
              @elseif(isset($diff['new']))
                <span class="text-green-400 ml-1">{{ $fv($diff['new']) }}</span>
              @endif
            </div>
          @endforeach
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap">
          {{ \Carbon\Carbon::parse($ch->recorded_at)->format('d M H:i') }}
        </td>
      </tr>
      @empty
      <tr><td colspan="5" class="px-5 py-12 text-center text-gray-600">No changes recorded yet</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- Pagination --}}
<div class="mt-4">
  {{ $changes->links() }}
</div>

@endsection
