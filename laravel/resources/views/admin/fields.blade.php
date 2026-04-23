@extends('admin.layout')
@section('title', 'Fields')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

{{-- Header --}}
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <p class="text-sm text-gray-500">
    Unified catalogue: every lot attribute, its source mapping, and fill-coverage per parser.
    Pre-computed — reads the <code class="text-gray-400">field_coverage_stats</code> table.
  </p>
  <form method="POST" action="{{ route('admin.fields.recompute') }}" class="flex items-center gap-3">
    @csrf
    @if($computedAt)
      <span class="text-xs text-gray-500">
        computed {{ $computedAt->diffForHumans() }}
      </span>
    @else
      <span class="text-xs text-amber-400">never computed — click Refresh</span>
    @endif
    <button type="submit"
            class="px-3 py-1.5 rounded-lg text-xs bg-gray-800 text-gray-300 hover:bg-gray-700 hover:text-white transition">
      ↻ Recompute
    </button>
  </form>
</div>

{{-- Summary strip --}}
<div class="flex flex-wrap gap-3 mb-6">
  <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-300">
    <span class="text-gray-500">Total fields:</span>
    <span class="text-white font-semibold ml-1">{{ $totalFields }}</span>
  </span>
  @foreach($sources as $src)
    @php
      $n = $totals[$src] ?? 0;
      $color = $src === 'encar' ? 'indigo' : ($src === 'kbcha' ? 'pink' : 'gray');
    @endphp
    <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-300">
      <span class="text-{{ $color }}-400 font-semibold">{{ $src }}</span>:
      <span class="text-white font-semibold ml-1">{{ number_format($n) }}</span>
      <span class="text-gray-500">lots</span>
    </span>
  @endforeach
  <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-500">
    schema v{{ $version }}
  </span>
</div>

{{-- Filter bar + Tables --}}
<div x-data="{ q: '', minPct: 0, showEmpty: true }">

  <div class="mb-6 flex flex-wrap items-center gap-3">
    <input type="text" x-model="q"
           placeholder="Filter by field name / column / raw location..."
           class="flex-1 min-w-[260px] bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600">

    <label class="flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-xs text-gray-400">
      <span>min %</span>
      <input type="number" min="0" max="100" step="10" x-model.number="minPct"
             class="w-14 bg-gray-800 border-none rounded text-sm text-white">
    </label>

    <label class="flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-xs text-gray-400 cursor-pointer">
      <input type="checkbox" x-model="showEmpty" class="w-3.5 h-3.5">
      show fields not populated
    </label>

    <button type="button" @click="q=''; minPct=0; showEmpty=true"
            class="px-3 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-xs text-gray-300">
      Clear
    </button>
  </div>

  @php
    // Pre-compute max coverage per field for min% filter (used as data-*)
    $maxCov = function ($coverage) {
        if (!$coverage) return 0;
        return max(array_column($coverage, 'pct'));
    };
  @endphp

  @foreach($grouped as $category => $items)
    <section class="mb-8">
      <h2 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3 px-1">
        {{ $category }}
        <span class="text-gray-600 font-normal normal-case">({{ count($items) }})</span>
      </h2>

      <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-900/60 text-gray-500 text-xs uppercase">
            <tr>
              <th class="px-4 py-2 text-left font-medium w-[180px]">Field</th>
              <th class="px-4 py-2 text-left font-medium w-[90px]">Type</th>
              @foreach($sources as $src)
                <th class="px-4 py-2 text-center font-medium w-[150px]">{{ $src }} coverage</th>
              @endforeach
              <th class="px-4 py-2 text-left font-medium">Sources & transform</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-800/60">
            @foreach($items as $f)
              @php
                $isEmpty = empty($f['extractions']) && empty($f['coverage']);
                $maxPct = $maxCov($f['coverage']);
                $searchBase = strtolower($f['name'] . ' ' . $f['db_column'] . ' '
                    . implode(' ', array_map(fn($e) => $e['raw_location'].' '.$e['transform'], $f['extractions'])));
              @endphp
              <tr x-show="(!q || '{{ $searchBase }}'.includes(q.toLowerCase())) && {{ $maxPct }} >= minPct && (showEmpty || {{ $isEmpty ? 'false' : 'true' }})"
                  class="hover:bg-gray-800/30">
                <td class="px-4 py-3 align-top">
                  <div class="font-mono text-xs text-blue-300">
                    {{ $f['name'] }}
                    @if($f['filterable'])
                      <span class="ml-1 text-[10px] px-1 rounded bg-purple-900/60 text-purple-300" title="used in filters">flt</span>
                    @endif
                    @if($f['tracked'])
                      <span class="ml-1 text-[10px] px-1 rounded bg-blue-900/60 text-blue-300" title="tracked in lot_changes">trk</span>
                    @endif
                  </div>
                  @if($f['db_column'] !== $f['name'])
                    <div class="text-[10px] text-gray-500 mt-1 font-mono">
                      db: {{ $f['db_column'] }}
                    </div>
                  @endif
                  @if($f['description'])
                    <div class="text-[11px] text-gray-500 mt-1">{{ $f['description'] }}</div>
                  @endif
                </td>
                <td class="px-4 py-3 align-top text-xs text-gray-400">
                  {{ $f['dtype'] }}
                </td>
                @foreach($sources as $src)
                  <td class="px-4 py-3 align-top">
                    @if(isset($f['coverage'][$src]))
                      @php
                        $c = $f['coverage'][$src];
                        $pct = $c['pct'];
                        $barColor = $pct >= 80 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-amber-400' : 'bg-red-500');
                        $txtColor = $pct >= 80 ? 'text-emerald-400' : ($pct >= 40 ? 'text-amber-400' : 'text-red-400');
                      @endphp
                      <div class="flex items-center gap-2">
                        <div class="flex-1 h-1.5 rounded-full bg-gray-800 overflow-hidden min-w-[50px]">
                          <div class="{{ $barColor }} h-full rounded-full" style="width:{{ min(100, $pct) }}%"></div>
                        </div>
                        <span class="text-xs font-bold {{ $txtColor }} w-12 text-right">{{ $pct }}%</span>
                      </div>
                      <div class="text-[10px] text-gray-600 text-right mt-0.5">
                        {{ number_format($c['filled']) }}/{{ number_format($c['total']) }}
                      </div>
                    @else
                      <div class="text-center text-xs text-gray-700">—</div>
                    @endif
                  </td>
                @endforeach
                <td class="px-4 py-3 align-top">
                  @if(empty($f['extractions']))
                    <span class="text-[11px] text-gray-600 italic">not mapped in field_mappings.py</span>
                  @else
                    <div class="space-y-1">
                      @foreach($f['extractions'] as $e)
                        <div class="text-[11px] flex items-start gap-2">
                          <span class="px-1.5 py-0.5 rounded uppercase tracking-wide
                            @if($e['source']==='encar') bg-indigo-900/60 text-indigo-300
                            @elseif($e['source']==='kbcha') bg-pink-900/60 text-pink-300
                            @else bg-gray-800 text-gray-400
                            @endif">
                            {{ $e['source'] }}
                          </span>
                          <div class="flex-1 font-mono text-gray-300 break-all">
                            {{ $e['raw_location'] }}
                            <span class="text-gray-600">→</span>
                            <span class="text-gray-500">{{ $e['transform'] }}</span>
                          </div>
                        </div>
                      @endforeach
                    </div>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endforeach
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

@endsection
