@extends('admin.layout')
@section('title', 'Дашборд')

@section('content')

@php
  $ui = \App\Support\AdminUiLabels::class;
@endphp

{{-- Source stats --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
  @foreach($sources as $src)
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="flex items-center justify-between mb-3">
      <span class="text-xs font-bold uppercase tracking-widest text-gray-500">{{ $ui::source($src->source) }}</span>
      <div class="flex items-center gap-2">
        <span class="text-xs px-2 py-0.5 rounded-full bg-green-900 text-green-400">активен</span>
        <form method="POST" action="{{ route('admin.jobs.launch') }}">
          @csrf
          <input type="hidden" name="source" value="{{ $src->source }}">
          <input type="hidden" name="max_pages" value="0">
          <button type="submit"
                  class="text-xs px-2 py-0.5 rounded bg-gray-800 text-gray-400 hover:bg-blue-900/50 hover:text-blue-300 transition"
                  title="Запустить парсер">▶ Запустить</button>
        </form>
      </div>
    </div>
    <div class="text-3xl font-bold text-white">{{ number_format($src->active) }}</div>
    <div class="text-sm text-gray-500 mt-1">{{ number_format($src->total) }} всего &nbsp;·&nbsp; {{ number_format($src->total - $src->active) }} неактивных</div>
    @if(isset($lastParsed[$src->source]))
    <div class="text-xs text-gray-600 mt-2">Последнее обновление: {{ \Carbon\Carbon::parse($lastParsed[$src->source])->diffForHumans() }}</div>
    @endif
    @if(isset($lastScheduled[$src->source]))
    @php $sched = $lastScheduled[$src->source]; @endphp
    <div class="text-xs mt-1 flex items-center gap-1.5">
      <span class="text-gray-600">⏱ Планировщик:</span>
      <span class="text-gray-500">{{ \Carbon\Carbon::parse($sched->last_run)->diffForHumans() }}</span>
      @php
        $sc = match($sched->last_status ?? '') {
          'done'    => 'text-green-500',
          'error'   => 'text-red-500',
          'running' => 'text-yellow-500',
          default   => 'text-blue-500',
        };
      @endphp
      <span class="text-xs {{ $sc }}">· {{ $ui::status($sched->last_status) }}</span>
    </div>
    @else
    <div class="text-xs text-gray-700 mt-1">⏱ Планировщик: не запускался</div>
    @endif
  </div>
  @endforeach
</div>

{{-- Last 24h change summary --}}
<div class="grid grid-cols-3 gap-4 mb-8">
  @foreach(['update' => 'blue', 'delisted' => 'red', 'relisted' => 'green'] as $ev => $color)
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
    <div class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">{{ $ui::event($ev) }} (24ч)</div>
    <div class="text-2xl font-bold text-{{ $color }}-400">{{ $changeSummary[$ev] ?? 0 }}</div>
  </div>
  @endforeach
</div>

{{-- Proxy balance (on-demand) --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-8" id="proxy-balance-card">
  <div class="flex items-center justify-between">
    <span class="text-xs font-bold uppercase tracking-widest text-gray-500">Прокси трафик (Floppydata)</span>
    <button onclick="loadProxyBalance()" id="proxy-balance-btn"
            class="text-xs px-3 py-1 rounded bg-gray-800 text-gray-400 hover:bg-blue-900/50 hover:text-blue-300 transition">
      Проверить баланс
    </button>
  </div>
  <div id="proxy-balance-result" class="mt-3 hidden">
    <div class="flex items-end gap-3 mb-3">
      <span class="text-3xl font-bold text-white" id="proxy-gb">—</span>
      <span class="text-sm text-gray-500 mb-1">ГБ остаток</span>
    </div>
    <div class="w-full bg-gray-800 rounded-full h-2">
      <div id="proxy-bar" class="bg-green-500 h-2 rounded-full transition-all" style="width: 0%"></div>
    </div>
    <div id="proxy-expires" class="text-xs text-gray-600 mt-2 hidden"></div>
  </div>
  <div id="proxy-balance-error" class="mt-2 text-xs text-red-500 hidden"></div>
</div>

<script>
function loadProxyBalance() {
  const btn = document.getElementById('proxy-balance-btn');
  btn.textContent = 'Загрузка…';
  btn.disabled = true;
  fetch('{{ route('admin.proxy.balance') }}')
    .then(r => r.json())
    .then(data => {
      if (data.error) { throw new Error(data.error); }
      const gb = ((data.nonExpiring?.gb ?? 0) + (data.subscription?.gb ?? 0));
      const pct = Math.min(100, Math.round(gb / 20 * 100));
      const color = pct > 50 ? 'bg-green-500' : (pct > 20 ? 'bg-yellow-500' : 'bg-red-500');
      document.getElementById('proxy-gb').textContent = gb.toFixed(2);
      const bar = document.getElementById('proxy-bar');
      bar.className = color + ' h-2 rounded-full transition-all';
      bar.style.width = pct + '%';
      if (data.subscription?.expiresOn) {
        const exp = document.getElementById('proxy-expires');
        exp.textContent = 'Подписка истекает: ' + new Date(data.subscription.expiresOn).toLocaleDateString('ru-RU');
        exp.classList.remove('hidden');
      }
      document.getElementById('proxy-balance-result').classList.remove('hidden');
      document.getElementById('proxy-balance-error').classList.add('hidden');
      btn.textContent = 'Обновить';
      btn.disabled = false;
    })
    .catch(e => {
      document.getElementById('proxy-balance-error').textContent = 'Ошибка: ' + e.message;
      document.getElementById('proxy-balance-error').classList.remove('hidden');
      btn.textContent = 'Проверить баланс';
      btn.disabled = false;
    });
}
</script>

{{-- Recent changes --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
    <span class="font-semibold text-white">Последние изменения</span>
    <a href="{{ route('admin.changes') }}"
       class="text-xs text-blue-400 hover:text-blue-300">Все →</a>
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
        <th class="px-5 py-3 text-left">Лот</th>
        <th class="px-5 py-3 text-left">Событие</th>
        <th class="px-5 py-3 text-left">Изменения</th>
        <th class="px-5 py-3 text-left">Когда</th>
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
          <span class="text-xs px-2 py-0.5 rounded-full {{ $badge }}">{{ $ui::event($ch->event) }}</span>
        </td>
        <td class="px-5 py-3 text-gray-300 text-xs">
          @php
            $fv = fn($v) => is_null($v) ? '—' : (is_bool($v) ? ($v ? 'да' : 'нет') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v));
          @endphp
          @foreach($ch->changes as $field => $diff)
            @if($field === 'is_active' && count($ch->changes) === 1)
              {{-- delisted/relisted: is_active alone is obvious from the badge --}}
              @continue
            @endif
            <span class="mr-2">
              <span class="text-gray-500">{{ $ui::field($field) }} <span class="text-gray-600">({{ $field }})</span>:</span>
              @if(isset($diff['old']))
                <span class="line-through text-gray-600 ml-1">{{ $fv($diff['old']) }}</span>
                <span class="text-blue-400 ml-1">→ {{ $fv($diff['new']) }}</span>
              @elseif(isset($diff['new']))
                <span class="text-green-400 ml-1">{{ $fv($diff['new']) }}</span>
              @endif
            </span>
          @endforeach
          @if(in_array($ch->event, ['delisted','relisted']) && count($ch->changes) <= 1)
            <span class="text-gray-600 italic">{{ $ui::event($ch->event) }}</span>
          @endif
        </td>
        <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap">
          {{ \Carbon\Carbon::parse($ch->recorded_at)->diffForHumans() }}
        </td>
      </tr>
      @empty
      <tr><td colspan="4" class="px-5 py-8 text-center text-gray-600">Изменений пока нет</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- Query timing --}}
<div class="mt-6 bg-gray-900 border border-gray-800 rounded-xl p-4">
  <div class="text-xs font-bold uppercase tracking-widest text-gray-600 mb-2">Время запросов</div>
  @php
    $dbgLabels = [
      'sources' => 'источники',
      'recentChanges' => 'последние изменения',
      'changeSummary' => 'сводка изменений',
      'lastScheduled' => 'последние расписания',
      'total' => 'всего',
    ];
  @endphp
  <div class="flex flex-wrap gap-3">
    @foreach($dbg as $label => $ms)
    <span class="text-xs px-2 py-1 rounded {{ $ms > 1000 ? 'bg-red-900 text-red-400' : ($ms > 200 ? 'bg-yellow-900 text-yellow-400' : 'bg-gray-800 text-gray-500') }}">
      {{ $dbgLabels[$label] ?? $label }}: {{ $ms }}мс
    </span>
    @endforeach
  </div>
</div>

@endsection
