@extends('admin.layout')
@section('title', 'Stats')

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  {{-- Daily changes chart (simple bar) --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="text-sm font-semibold text-white mb-4">Changes per day (last 14 days)</div>
    @php $maxCnt = $dailyChanges->max('cnt') ?: 1; @endphp
    <div class="flex items-end gap-1 h-32">
      @foreach($dailyChanges as $day)
      <div class="flex-1 flex flex-col items-center gap-1 group">
        <div class="w-full bg-blue-600 rounded-t transition hover:bg-blue-500"
             style="height: {{ round(($day->cnt / $maxCnt) * 100) }}%"
             title="{{ $day->day }}: {{ $day->cnt }}">
        </div>
        <span class="text-gray-600 text-xs rotate-45 origin-left hidden group-hover:block absolute">{{ \Carbon\Carbon::parse($day->day)->format('d M') }}</span>
      </div>
      @endforeach
    </div>
    <div class="flex justify-between text-xs text-gray-600 mt-2">
      <span>{{ $dailyChanges->first()?->day ?? '' }}</span>
      <span>{{ $dailyChanges->last()?->day ?? '' }}</span>
    </div>
  </div>

  {{-- Top changed lots --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-800">
      <span class="font-semibold text-white text-sm">Most changed lots (7 days)</span>
    </div>
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-800">
        @forelse($topChanged as $row)
        <tr class="hover:bg-gray-800/50">
          <td class="px-5 py-2.5 font-mono text-xs text-gray-400">{{ $row->lot_id }}</td>
          <td class="px-5 py-2.5 text-right">
            <span class="text-xs px-2 py-0.5 rounded-full bg-blue-900 text-blue-400">{{ $row->cnt }} changes</span>
          </td>
        </tr>
        @empty
        <tr><td colspan="2" class="px-5 py-8 text-center text-gray-600">No data yet</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

</div>

@endsection
