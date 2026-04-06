@extends('admin.layout')
@section('title', 'Changes')

@section('content')

{{-- Filter bar --}}
<div class="flex gap-2 mb-6">
  @foreach(['', 'update', 'delisted', 'relisted'] as $ev)
  <a href="{{ route('admin.changes', array_filter(['token' => request()->query('token'), 'event' => $ev])) }}"
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
          @foreach($ch->changes as $field => $diff)
          <div>
            <span class="text-gray-500">{{ $field }}:</span>
            @if(isset($diff['old']))
              <span class="line-through text-gray-600 ml-1">{{ is_array($diff['old']) ? json_encode($diff['old']) : $diff['old'] }}</span>
              <span class="text-green-400 ml-1">→ {{ is_array($diff['new']) ? json_encode($diff['new']) : $diff['new'] }}</span>
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
