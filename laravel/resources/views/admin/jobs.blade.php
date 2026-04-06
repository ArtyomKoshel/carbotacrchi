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
  <form method="POST" action="{{ route('admin.jobs.launch', ['token' => request()->query('token')]) }}"
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
        <td class="px-5 py-3 text-gray-600 text-xs">{{ $job->id }}</td>
        <td class="px-5 py-3 text-white">{{ $job->source }}</td>
        <td class="px-5 py-3">
          @php
            $badge = match($job->status) {
              'done'      => 'bg-green-900 text-green-400',
              'error'     => 'bg-red-900 text-red-400',
              'running'   => 'bg-yellow-900 text-yellow-400',
              'cancelled' => 'bg-gray-800 text-gray-500',
              default     => 'bg-blue-900/50 text-blue-400',
            };
          @endphp
          <span class="status-badge text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $job->status }}</span>
        </td>
        <td class="px-5 py-3 text-xs text-gray-400 progress-cell">
          @if($job->progress)
            @if(isset($job->progress['page']))
              p.{{ $job->progress['page'] }}
              @if(isset($job->progress['found_total'])) · {{ $job->progress['found_total'] }} found @endif
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
              {{ $job->result['total'] ?? 0 }} lots · {{ $job->result['pages'] ?? 0 }} pages
            @endif
          @endif
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap">
          {{ $job->created_at->diffForHumans() }}
        </td>
        <td class="px-5 py-3 text-right flex items-center gap-2 justify-end">
          @if(in_array($job->status, ['running', 'pending']))
            <button onclick="watchJob({{ $job->id }}, '{{ $job->source }}')"
                    class="px-2 py-1 rounded text-xs bg-blue-900/50 text-blue-300 hover:bg-blue-900 transition">
              Watch
            </button>
          @endif
          @if($job->status === 'pending')
            <form method="POST"
                  action="{{ route('admin.jobs.cancel', ['id' => $job->id, 'token' => request()->query('token')]) }}">
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

{{-- Live progress panel --}}
<div id="progress-panel" class="hidden fixed bottom-4 right-4 w-96 bg-gray-900 border border-gray-700 rounded-xl shadow-2xl overflow-hidden z-50">
  <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
    <span class="text-sm font-semibold text-white" id="progress-title">Job progress</span>
    <button onclick="closeProgress()" class="text-gray-500 hover:text-white text-lg leading-none">×</button>
  </div>
  <div class="p-4 space-y-2 text-xs font-mono" id="progress-log" style="max-height:260px;overflow-y:auto"></div>
</div>

<script>
const token = {{ json_encode(request()->query('token')) }};
let es = null;

function watchJob(id, source) {
  if (es) es.close();
  const panel = document.getElementById('progress-panel');
  const log   = document.getElementById('progress-log');
  const title = document.getElementById('progress-title');
  log.innerHTML = '';
  title.textContent = `Job #${id} — ${source}`;
  panel.classList.remove('hidden');

  es = new EventSource(`/admin/jobs/${id}/progress?token=${token}`);
  es.onmessage = (e) => {
    const d = JSON.parse(e.data);
    const line = document.createElement('div');
    const color = d.status === 'error' ? 'text-red-400'
                : d.status === 'done'  ? 'text-green-400'
                : 'text-gray-300';
    line.className = color;
    line.textContent = JSON.stringify(d);
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;

    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
      row.dataset.status = d.status;
      const badge = row.querySelector('.status-badge');
      const colors = { done:'bg-green-900 text-green-400', error:'bg-red-900 text-red-400',
                       running:'bg-yellow-900 text-yellow-400', pending:'bg-blue-900/50 text-blue-400' };
      badge.className = `status-badge text-xs px-2 py-0.5 rounded-full ${colors[d.status] ?? ''}`;
      badge.textContent = d.status;
      if (d.found_total !== undefined) {
        row.querySelector('.progress-cell').textContent = `p.${d.page} · ${d.found_total} found`;
      }
      if (d.total !== undefined) {
        row.querySelector('.result-cell').textContent = `${d.total} lots · ${d.pages} pages`;
      }
    }
    if (['done','error'].includes(d.status)) { es.close(); es = null; }
  };
  es.onerror = () => { es.close(); es = null; };
}

function closeProgress() {
  if (es) { es.close(); es = null; }
  document.getElementById('progress-panel').classList.add('hidden');
}

// auto-watch if any job is currently running on page load
document.querySelectorAll('tr[data-status="running"]').forEach(row => {
  watchJob(parseInt(row.dataset.id), row.dataset.source);
});
</script>

@endsection
