@extends('admin.layout')
@section('title', 'Parse Jobs')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

{{-- Launch form --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
  <div class="text-sm font-semibold text-white mb-4">Launch Parser</div>
  <form method="POST" action="{{ route('admin.jobs.launch') }}"
        class="flex flex-wrap gap-3 items-end">
    @csrf
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500">Source</label>
      <select name="source"
              class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        @foreach($sources as $src)
        <option value="{{ $src }}">{{ $src }}</option>
        @endforeach
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500">Max pages <span class="text-gray-600">(0 = from schedule)</span></label>
      <input type="number" name="max_pages" min="0" value="0"
             class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
    </div>
    <div class="flex flex-col gap-1">
      <label class="text-xs text-gray-500">Maker filter <span class="text-gray-600">(optional)</span></label>
      <input type="text" name="maker" placeholder="e.g. Hyundai"
             class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-40 placeholder-gray-600">
    </div>
    <button type="submit"
            class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
      ▶ Run now
    </button>
  </form>
</div>

{{-- Jobs table --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 font-semibold text-white text-sm">
    Job History
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left w-10">#</th>
        <th class="px-5 py-3 text-left">Source</th>
        <th class="px-5 py-3 text-left">Status</th>
        <th class="px-5 py-3 text-left">Progress</th>
        <th class="px-5 py-3 text-left">Result</th>
        <th class="px-5 py-3 text-left">Started</th>
        <th class="px-5 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800" id="jobs-table">
      @forelse($jobs as $job)
      <tr data-id="{{ $job->id }}" data-status="{{ $job->status }}" data-source="{{ $job->source }}">
        <td class="px-5 py-3 text-xs">
          <a href="{{ route('admin.jobs.detail', $job->id) }}" class="text-blue-400 hover:text-blue-300 underline">{{ $job->id }}</a>
        </td>
        <td class="px-5 py-3 text-white">
          {{ $job->source }}
          @if($job->triggered_by === 'scheduler')
            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-purple-900/50 text-purple-400">⏱ auto</span>
          @else
            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-gray-800 text-gray-500">manual</span>
          @endif
        </td>
        <td class="px-5 py-3">
          @php
            $badge = match($job->status) {
              'done'        => 'bg-green-900 text-green-400',
              'error'       => 'bg-red-900 text-red-400',
              'running'     => 'bg-yellow-900 text-yellow-400',
              'interrupted' => 'bg-orange-900 text-orange-400',
              'cancelled'   => 'bg-gray-800 text-gray-500',
              default       => 'bg-blue-900/50 text-blue-400',
            };
          @endphp
          <span class="status-badge text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $job->status }}</span>
        </td>
        <td class="px-5 py-3 text-xs text-gray-400 progress-cell">
          @if($job->progress)
            @if(isset($job->progress['pct']))
              <span class="text-white font-semibold">{{ $job->progress['pct'] }}%</span>
              <span class="text-gray-600 ml-1">{{ number_format($job->progress['found_total'] ?? 0) }} / {{ number_format($job->progress['api_total'] ?? 0) }}</span>
            @else
              {{ $job->progress['status'] ?? '' }}
            @endif
          @endif
        </td>
        <td class="px-5 py-3 text-xs text-gray-400 result-cell">
          @if($job->result)
            @if(isset($job->result['error']))
              <span class="text-red-400">{{ Str::limit($job->result['error'], 60) }}</span>
            @else
              <span class="text-white font-semibold">{{ number_format($job->result['total'] ?? 0) }}</span> lots
              @if(isset($job->result['coverage_pct']))
                <span class="text-gray-600">·</span>
                <span class="{{ ($job->result['coverage_pct'] ?? 0) >= 95 ? 'text-green-400' : 'text-yellow-400' }}">{{ $job->result['coverage_pct'] }}%</span>
              @endif
              @if(isset($job->result['new']) && $job->result['new'])
                <span class="text-gray-600">·</span>
                <span class="text-blue-400">+{{ number_format($job->result['new']) }}</span>
              @endif
              @if(isset($job->result['stale']) && $job->result['stale'])
                <span class="text-gray-600">·</span>
                <span class="text-orange-400">-{{ number_format($job->result['stale']) }}</span>
              @endif
              @if(isset($job->result['errors']) && $job->result['errors'])
                <span class="text-gray-600">·</span>
                <span class="text-red-400">{{ $job->result['errors'] }} err</span>
              @endif
              @if(isset($job->result['time']))
                <span class="text-gray-600">·</span>
                <span class="text-gray-500">{{ $job->result['time'] }}</span>
              @endif
            @endif
          @endif
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap">
          {{ $job->created_at->diffForHumans() }}
        </td>
        <td class="px-5 py-3 text-right flex items-center gap-2 justify-end">
          <a href="{{ route('admin.jobs.detail', $job->id) }}"
             class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-white transition">
            Details
          </a>
          @if(in_array($job->status, ['pending', 'running', 'interrupted']))
            <form method="POST"
                  action="{{ route('admin.jobs.cancel', ['id' => $job->id]) }}">
              @csrf
              <button type="submit" class="px-2 py-1 rounded text-xs bg-gray-800 text-gray-400 hover:text-red-400 transition">
                Cancel
              </button>
            </form>
          @endif
        </td>
      </tr>
      @empty
      <tr><td colspan="7" class="px-5 py-12 text-center text-gray-600">No jobs yet. Launch one above.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<div class="mt-4">{{ $jobs->withQueryString()->links() }}</div>

<script>
// Lightweight SSE: only update table rows for running jobs, no popup
function watchJobRow(id, source) {
  const es = new EventSource(`/admin/jobs/${id}/progress`);
  es.onmessage = (e) => {
    const d = JSON.parse(e.data);
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    row.dataset.status = d.status;
    const badge = row.querySelector('.status-badge');
    const colors = { done:'bg-green-900 text-green-400', error:'bg-red-900 text-red-400',
      running:'bg-yellow-900 text-yellow-400', pending:'bg-blue-900/50 text-blue-400',
      cancelled:'bg-gray-800 text-gray-500' };
    badge.className = `status-badge text-xs px-2 py-0.5 rounded-full ${colors[d.status] ?? ''}`;
    badge.textContent = d.status;
    if (d.pct !== undefined) row.querySelector('.progress-cell').innerHTML = `<span class="text-white font-semibold">${d.pct}%</span> <span class="text-gray-600">${(d.found_total??0).toLocaleString()} / ${(d.api_total??0).toLocaleString()}</span>`;
    if (d.total !== undefined) {
      let r = `<span class="text-white font-semibold">${d.total.toLocaleString()}</span> lots`;
      if (d.new) r += ` <span class="text-gray-600">·</span> <span class="text-blue-400">+${d.new.toLocaleString()}</span>`;
      if (d.errors) r += ` <span class="text-gray-600">·</span> <span class="text-red-400">${d.errors} err</span>`;
      row.querySelector('.result-cell').innerHTML = r;
    }
    if (['done','error','cancelled'].includes(d.status)) {
      es.close();
      setTimeout(() => location.reload(), 1500);
    }
  };
  es.onerror = () => es.close();
}

document.querySelectorAll('tr[data-status="running"], tr[data-status="pending"], tr[data-status="interrupted"]').forEach(row => {
  watchJobRow(parseInt(row.dataset.id), row.dataset.source);
});
</script>

@endsection
