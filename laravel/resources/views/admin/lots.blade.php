@extends('admin.layout')
@section('title', 'Re-parse Lot')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

{{-- Search form --}}
<form method="GET" action="{{ route('admin.lots') }}"
      class="flex gap-3 mb-6">
  <input type="text" name="q" value="{{ $q }}" placeholder="Lot ID / plate / VIN..."
         class="flex-1 bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500">
  <button type="submit"
          class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm transition">
    Search
  </button>
</form>

{{-- Search results --}}
@if($q !== '' && $lots->isNotEmpty())
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
  <div class="px-5 py-3 border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wide">
    Results for "{{ $q }}"
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left">ID</th>
        <th class="px-5 py-3 text-left">Make / Model</th>
        <th class="px-5 py-3 text-left">Plate</th>
        <th class="px-5 py-3 text-left">Status</th>
        <th class="px-5 py-3 text-left">Parsed</th>
        <th class="px-5 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
      @foreach($lots as $lot)
      <tr class="hover:bg-gray-800/50">
        <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $lot->id }}</td>
        <td class="px-5 py-3 text-white">{{ $lot->make }} {{ $lot->model }}
          @if($lot->year)<span class="text-gray-500 ml-1">{{ $lot->year }}</span>@endif
        </td>
        <td class="px-5 py-3 text-gray-400 text-xs">{{ $lot->plate_number ?? '—' }}</td>
        <td class="px-5 py-3">
          <span class="text-xs px-2 py-0.5 rounded-full {{ $lot->is_active ? 'bg-green-900 text-green-400' : 'bg-gray-800 text-gray-500' }}">
            {{ $lot->is_active ? 'active' : 'inactive' }}
          </span>
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs">
          {{ $lot->parsed_at ? \Carbon\Carbon::parse($lot->parsed_at)->diffForHumans() : '—' }}
        </td>
        <td class="px-5 py-3 text-right">
          <form method="POST"
                action="{{ route('admin.lots.reparse', ['lotId' => $lot->id]) }}">
            @csrf
            <button type="submit"
                    class="px-3 py-1 rounded-lg bg-blue-700 hover:bg-blue-600 text-white text-xs transition">
              ⟳ Re-parse
            </button>
          </form>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@elseif($q !== '')
<div class="text-gray-500 text-sm mb-6">No lots found for "{{ $q }}"</div>
@endif

{{-- Recent reparse queue --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 font-semibold text-white text-sm">
    Recent re-parse requests
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left">Lot ID</th>
        <th class="px-5 py-3 text-left">Status</th>
        <th class="px-5 py-3 text-left">Result</th>
        <th class="px-5 py-3 text-left">Requested</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800" id="reparse-table">
      @forelse($recent as $req)
      <tr data-id="{{ $req->id }}" data-status="{{ $req->status }}">
        <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $req->lot_id }}</td>
        <td class="px-5 py-3">
          @php
            $badge = match($req->status) {
              'done'    => 'bg-green-900 text-green-400',
              'error'   => 'bg-red-900 text-red-400',
              'running' => 'bg-yellow-900 text-yellow-400',
              default   => 'bg-gray-800 text-gray-400',
            };
          @endphp
          <span class="status-badge text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $req->status }}</span>
        </td>
        <td class="px-5 py-3 text-gray-400 text-xs result-cell">{{ $req->result ?? '' }}</td>
        <td class="px-5 py-3 text-gray-500 text-xs">
          {{ $req->created_at->diffForHumans() }}
        </td>
      </tr>
      @empty
      <tr><td colspan="4" class="px-5 py-8 text-center text-gray-600">No requests yet</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<script>
const statusColors = {
  done:    'bg-green-900 text-green-400',
  error:   'bg-red-900 text-red-400',
  running: 'bg-yellow-900 text-yellow-400',
  pending: 'bg-gray-800 text-gray-400',
};

function pollPending() {
  const rows = document.querySelectorAll('#reparse-table tr[data-status="pending"], #reparse-table tr[data-status="running"]');
  rows.forEach(row => {
    const id = row.dataset.id;
    fetch(`/admin/reparse/${id}/status`)
      .then(r => r.json())
      .then(data => {
        row.dataset.status = data.status;
        const badge = row.querySelector('.status-badge');
        badge.className = 'status-badge text-xs px-2 py-0.5 rounded-full ' + (statusColors[data.status] ?? '');
        badge.textContent = data.status;
        if (data.result) row.querySelector('.result-cell').textContent = data.result;
      });
  });
}

setInterval(pollPending, 4000);
</script>

@endsection
