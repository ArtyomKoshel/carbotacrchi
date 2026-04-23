@extends('admin.layout')
@section('title', 'Field map')

@section('content')

@if($version === 0)
  <div class="mb-4 px-4 py-3 rounded-lg bg-yellow-900/40 border border-yellow-700 text-yellow-300 text-sm">
    <p class="font-semibold mb-1">Catalogue could not be loaded</p>
    <p class="mt-1 text-yellow-400/80">
      Run <code class="text-yellow-200">php artisan parser:export-fields</code>
      or ensure <code class="text-yellow-200">python -m parsers._shared.field_mappings --json</code>
      is reachable from the Laravel container. Showing empty view.
    </p>
  </div>
@endif

<p class="text-sm text-gray-500 mb-4">
  Catalogue of every <code class="text-gray-400">lots</code> column and
  which parser feeds it. Read-only — sourced from
  <code class="text-gray-400">parser/parsers/_shared/field_mappings.py</code>.
</p>

{{-- Summary strip --}}
<div class="flex flex-wrap gap-3 mb-6">
  <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-300">
    <span class="text-gray-500">Total attrs:</span>
    <span class="text-white font-semibold ml-1">{{ $totalFields }}</span>
  </span>
  @foreach($coverage as $src => $count)
    <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-300">
      <span class="text-gray-500">{{ $src }}:</span>
      <span class="text-white font-semibold ml-1">{{ $count }}</span>
      <span class="text-gray-500">/ {{ $totalFields }}</span>
    </span>
  @endforeach
  <span class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 text-xs text-gray-500">
    schema v{{ $version }}
  </span>
</div>

<div x-data="{ q: '', src: '' }">
  {{-- Filter bar --}}
  <div class="mb-6 flex flex-wrap items-center gap-3">
    <input type="text" x-model="q" placeholder="Filter by attribute / column / location..."
           class="flex-1 min-w-[260px] bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-600">
    <select x-model="src"
            class="bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-sm text-white">
      <option value="">All sources</option>
      @foreach($sources as $s)
        <option value="{{ $s }}">{{ $s }}</option>
      @endforeach
    </select>
    <button type="button" @click="q=''; src=''"
            class="px-3 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-xs text-gray-300">
      Clear
    </button>
  </div>

  {{-- Tables grouped by category --}}
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
              <th class="px-4 py-2 text-left font-medium w-[170px]">Attribute</th>
              <th class="px-4 py-2 text-left font-medium w-[140px]">DB column</th>
              <th class="px-4 py-2 text-left font-medium w-[80px]">Type</th>
              <th class="px-4 py-2 text-left font-medium w-[80px]">Source</th>
              <th class="px-4 py-2 text-left font-medium">Raw location</th>
              <th class="px-4 py-2 text-left font-medium w-[240px]">Transform</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-800/60">
            @foreach($items as $m)
              @php
                $rows = count($m['extractions']) ?: 1;
                $first = true;
                $searchBase = strtolower($m['attribute'].' '.$m['db_column']);
              @endphp
              @if(empty($m['extractions']))
                <tr x-show="(!q || '{{ $searchBase }}'.includes(q.toLowerCase())) && !src">
                  <td class="px-4 py-2 align-top font-mono text-xs text-blue-300">
                    {{ $m['attribute'] }}
                    @if($m['filterable'])
                      <span class="ml-1 text-[10px] px-1 rounded bg-purple-900/60 text-purple-300">flt</span>
                    @endif
                  </td>
                  <td class="px-4 py-2 align-top font-mono text-xs text-gray-300">{{ $m['db_column'] }}</td>
                  <td class="px-4 py-2 align-top text-xs text-gray-400">{{ $m['dtype'] ?? '—' }}</td>
                  <td colspan="3" class="px-4 py-2 align-top text-xs text-gray-600 italic">
                    not populated by any parser
                  </td>
                </tr>
              @else
                @foreach($m['extractions'] as $e)
                  @php
                    $rowSearch = strtolower($searchBase.' '.$e['source'].' '.$e['raw_location'].' '.$e['transform']);
                  @endphp
                  <tr x-show="(!q || '{{ $rowSearch }}'.includes(q.toLowerCase())) && (!src || src === '{{ $e['source'] }}')">
                    @if($first)
                      <td class="px-4 py-2 align-top font-mono text-xs text-blue-300" rowspan="{{ $rows }}">
                        {{ $m['attribute'] }}
                        @if($m['filterable'])
                          <span class="ml-1 text-[10px] px-1 rounded bg-purple-900/60 text-purple-300">flt</span>
                        @endif
                        @if($m['notes'])
                          <div class="mt-1 text-[10px] text-gray-600 font-normal normal-case">
                            {{ $m['notes'] }}
                          </div>
                        @endif
                      </td>
                      <td class="px-4 py-2 align-top font-mono text-xs text-gray-300" rowspan="{{ $rows }}">
                        {{ $m['db_column'] }}
                      </td>
                      <td class="px-4 py-2 align-top text-xs text-gray-400" rowspan="{{ $rows }}">
                        {{ $m['dtype'] ?? '—' }}
                      </td>
                      @php $first = false; @endphp
                    @endif
                    <td class="px-4 py-2 align-top">
                      <span class="text-[10px] px-2 py-0.5 rounded uppercase tracking-wide
                        @if($e['source']==='encar') bg-indigo-900/60 text-indigo-300
                        @elseif($e['source']==='kbcha') bg-pink-900/60 text-pink-300
                        @else bg-gray-800 text-gray-400
                        @endif">
                        {{ $e['source'] }}
                      </span>
                    </td>
                    <td class="px-4 py-2 align-top text-xs text-gray-300 font-mono">
                      {{ $e['raw_location'] }}
                      @if($e['notes'])
                        <div class="mt-1 text-[10px] text-gray-600 normal-case font-sans">
                          {{ $e['notes'] }}
                        </div>
                      @endif
                    </td>
                    <td class="px-4 py-2 align-top text-xs text-gray-400 font-mono break-all">
                      {{ $e['transform'] }}
                    </td>
                  </tr>
                @endforeach
              @endif
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endforeach
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

@endsection
