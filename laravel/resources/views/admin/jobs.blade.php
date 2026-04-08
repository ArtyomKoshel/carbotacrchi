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
        <td class="px-5 py-3 text-gray-600 text-xs">{{ $job->id }}</td>
        <td class="px-5 py-3 text-white">
          {{ $job->source }}
          @if(($job->filters['triggered_by'] ?? 'manual') === 'scheduler')
            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-purple-900/50 text-purple-400">⏱ auto</span>
          @else
            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-gray-800 text-gray-500">manual</span>
          @endif
        </td>
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
          @if(in_array($job->status, ['pending', 'running']))
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

{{-- Live progress panel --}}
<div id="progress-panel" class="hidden fixed bottom-4 right-4 w-[480px] bg-gray-900 border border-gray-700 rounded-xl shadow-2xl overflow-hidden z-50">
  <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
    <div class="flex items-center gap-3">
      <span class="text-sm font-semibold text-white" id="progress-title">Job progress</span>
      <span class="text-xs text-gray-500 font-mono" id="progress-lifetime"></span>
    </div>
    <button onclick="closeProgress()" class="text-gray-500 hover:text-white text-lg leading-none">×</button>
  </div>
  {{-- Tabs --}}
  <div class="flex border-b border-gray-800 text-xs">
    <button id="tab-progress" onclick="switchTab('progress')"
            class="px-4 py-2 text-blue-400 border-b-2 border-blue-500 font-semibold">Progress</button>
    <button id="tab-lots" onclick="switchTab('lots')"
            class="px-4 py-2 text-gray-500 hover:text-white">Lots <span id="lots-count" class="ml-1 text-gray-600"></span></button>
  </div>
  <div id="pane-progress" class="p-3 text-xs font-mono space-y-0.5" style="max-height:300px;overflow-y:auto"></div>
  <div id="pane-lots"     class="hidden p-3 text-xs space-y-1"      style="max-height:300px;overflow-y:auto"></div>
</div>

<script>
let es = null, lotsTimer = null, watchId = null, lifetimeTimer = null, lifetimeStart = null;

function switchTab(tab) {
  const tabs = ['progress','lots'];
  tabs.forEach(t => {
    document.getElementById(`tab-${t}`).className =
      t === tab ? 'px-4 py-2 text-blue-400 border-b-2 border-blue-500 font-semibold text-xs'
                : 'px-4 py-2 text-gray-500 hover:text-white text-xs';
    document.getElementById(`pane-${t}`).classList.toggle('hidden', t !== tab);
  });
}

function fmtElapsed(s) {
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  return h ? `${h}h ${m}m ${sec}s` : m ? `${m}m ${sec}s` : `${sec}s`;
}

function watchJob(id, source) {
  if (es) { es.close(); es = null; }
  if (lotsTimer) { clearInterval(lotsTimer); lotsTimer = null; }
  if (lifetimeTimer) { clearInterval(lifetimeTimer); lifetimeTimer = null; }
  watchId = id;

  const panel = document.getElementById('progress-panel');
  document.getElementById('pane-progress').innerHTML = '';
  document.getElementById('pane-lots').innerHTML = '';
  document.getElementById('lots-count').textContent = '';
  document.getElementById('progress-title').textContent = `Job #${id} — ${source}`;
  panel.classList.remove('hidden');
  switchTab('progress');

  // Lifetime timer
  lifetimeStart = Math.floor(Date.now() / 1000);
  const ltEl = document.getElementById('progress-lifetime');
  ltEl.textContent = '0s';
  lifetimeTimer = setInterval(() => {
    ltEl.textContent = fmtElapsed(Math.floor(Date.now() / 1000) - lifetimeStart);
  }, 1000);

  // SSE progress stream
  es = new EventSource(`/admin/jobs/${id}/progress`);
  es.onmessage = (e) => {
    const d = JSON.parse(e.data);
    const pane = document.getElementById('pane-progress');
    const line = document.createElement('div');
    const color = d.status === 'error' ? 'text-red-400'
                : d.status === 'done' || d.status === 'cancelled' ? 'text-green-400'
                : d.status === 'pending' ? 'text-gray-600'
                : 'text-gray-300';
    line.className = color;
    if (d.page !== undefined) {
      line.textContent = `p.${d.page} · ${d.found_total ?? 0} found`;
    } else {
      line.textContent = d.status + (d.error ? ': ' + d.error : d.total !== undefined ? ` — ${d.total} lots` : '');
    }
    pane.appendChild(line);
    pane.scrollTop = pane.scrollHeight;

    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
      row.dataset.status = d.status;
      const badge = row.querySelector('.status-badge');
      const colors = { done:'bg-green-900 text-green-400', error:'bg-red-900 text-red-400',
        running:'bg-yellow-900 text-yellow-400', pending:'bg-blue-900/50 text-blue-400',
        cancelled:'bg-gray-800 text-gray-500' };
      badge.className = `status-badge text-xs px-2 py-0.5 rounded-full ${colors[d.status] ?? ''}`;
      badge.textContent = d.status;
      if (d.found_total !== undefined) row.querySelector('.progress-cell').textContent = `p.${d.page} · ${d.found_total} found`;
      if (d.total !== undefined) row.querySelector('.result-cell').textContent = `${d.total} lots · ${d.pages} pages`;
    }
    if (['done','error','cancelled'].includes(d.status)) {
      es.close(); es = null;
      if (lifetimeTimer) { clearInterval(lifetimeTimer); lifetimeTimer = null; }
    }
  };
  es.onerror = () => { es.close(); es = null; };

  // Poll all processed lots every 5s
  const pollLots = () => {
    fetch(`/admin/jobs/${id}/events`)
      .then(r => r.json())
      .then(data => {
        const pane = document.getElementById('pane-lots');
        pane.innerHTML = '';
        const fmtPrice = (p) => p ? `$${Number(p).toLocaleString()}` : '';
        const fmtMi = (m) => m ? `${Number(m).toLocaleString()} km` : '';
        (data.lots || []).forEach(lot => {
          const div = document.createElement('div');
          div.className = 'flex items-center gap-2 border-b border-gray-800/50 pb-1';
          const badge = lot.changed
            ? '<span class="text-[10px] px-1 rounded bg-yellow-900/60 text-yellow-400">upd</span>'
            : '<span class="text-[10px] px-1 rounded bg-gray-800 text-gray-600">ok</span>';
          div.innerHTML = `${badge}
            <span class="text-gray-300 font-mono shrink-0">${lot.id}</span>
            <span class="text-gray-400 truncate flex-1">${lot.title}</span>
            <span class="text-gray-500 shrink-0">${fmtPrice(lot.price)}</span>
            <span class="text-gray-600 shrink-0">${fmtMi(lot.mileage)}</span>`;
          pane.appendChild(div);
        });
        const chTxt = data.changed ? `, ${data.changed} changed` : '';
        document.getElementById('lots-count').textContent = data.total ? `(${data.total}${chTxt})` : '';
        if (['done','error','cancelled'].includes(data.status)) {
          clearInterval(lotsTimer); lotsTimer = null;
        }
      }).catch(() => {});
  };
  pollLots();
  lotsTimer = setInterval(pollLots, 3000);
}

function closeProgress() {
  if (es) { es.close(); es = null; }
  if (lotsTimer) { clearInterval(lotsTimer); lotsTimer = null; }
  if (lifetimeTimer) { clearInterval(lifetimeTimer); lifetimeTimer = null; }
  document.getElementById('progress-panel').classList.add('hidden');
}

document.querySelectorAll('tr[data-status="running"]').forEach(row => {
  watchJob(parseInt(row.dataset.id), row.dataset.source);
});
</script>

@endsection
