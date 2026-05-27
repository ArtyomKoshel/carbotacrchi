@extends('admin.layout')
@section('title', 'Таксономия')

@section('content')
@if(session('success'))
  <div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">{{ session('error') }}</div>
@endif

@php
  $actionLabels = [
    'set_trim' => 'Заполнить Trim',
    'set_generation' => 'Заполнить Generation',
    'strip_tail' => 'Убрать хвост из модели',
    'replace_model' => 'Заменить Model',
  ];
@endphp

<div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
  <h2 class="text-lg font-semibold text-white">Таксономия: правила + очередь аномалий</h2>
  @if(session('admin_role') === 'super')
  <form method="post" action="{{ route('admin.taxonomy.ingest') }}" class="flex items-center gap-2">
    @csrf
    <input type="hidden" name="source" value="{{ $source }}">
    <input type="number" name="max" min="100" max="500000" value="50000"
      class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
    <button class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs">Загрузить аномалии</button>
  </form>

  <form method="post" action="{{ route('admin.taxonomy.bootstrap') }}" class="flex items-center gap-2">
    @csrf
    <input type="hidden" name="source" value="{{ $source }}">
    <input type="number" name="min_seen" min="1" max="5000" value="5"
      class="w-20 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white" title="Минимум повторов">
    <input type="number" name="min_confidence" min="0" max="1" step="0.01" value="0.80"
      class="w-24 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white" title="Порог confidence">
    <input type="hidden" name="actions" value="set_trim">
    <input type="hidden" name="apply" value="1">
    <button class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs">Сгенерировать базовые правила</button>
  </form>
  @endif
</div>

<form method="get" class="mb-4 flex items-center gap-2 flex-wrap">
  <input type="text" name="source" value="{{ $source }}" placeholder="source"
    class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
  <select name="status" class="bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
    @foreach(['new' => 'Новые', 'rule_created' => 'С правилом', 'ignored' => 'Игнор', '' => 'Все'] as $k => $label)
      <option value="{{ $k }}" {{ $status === $k ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select>
  <button class="px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white text-xs">Фильтр</button>
</form>

@if(session('admin_role') === 'super')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-5">
  <h3 class="text-sm text-gray-300 font-semibold mb-3">Новое правило</h3>
  <form method="post" action="{{ route('admin.taxonomy.rules.store') }}" class="grid md:grid-cols-8 gap-2">
    @csrf
    <input name="source" value="{{ $source }}" placeholder="source" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="make" placeholder="make" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="model_contains" placeholder="model contains (опц.)" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="unknown_tail" placeholder="unknown tail (опц.)" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <select name="action" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
      @foreach(['set_trim','set_generation','strip_tail','replace_model'] as $action)
        <option value="{{ $action }}">{{ $actionLabels[$action] ?? $action }}</option>
      @endforeach
    </select>
    <input name="action_value" placeholder="Значение" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input type="number" name="priority" value="100" min="1" max="10000" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <button class="rounded bg-green-600 hover:bg-green-500 text-white text-sm px-3">Добавить</button>
  </form>
</div>
@endif

<div class="grid lg:grid-cols-2 gap-4">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 overflow-auto">
    <h3 class="text-sm text-gray-300 font-semibold mb-3">Правила</h3>
    <table class="w-full text-xs text-gray-300">
      <thead class="text-gray-500 border-b border-gray-800">
        <tr><th class="text-left py-2">Условие</th><th class="text-left py-2">Действие</th><th class="text-left py-2">Мета</th></tr>
      </thead>
      <tbody>
        @foreach($rules as $rule)
          <tr class="border-b border-gray-800 align-top">
            <td class="py-2">
              <div>{{ $rule->source }} {{ $rule->make ? '· '.$rule->make : '' }}</div>
              <div class="text-gray-500">tail={{ $rule->unknown_tail ?: '—' }} · model~={{ $rule->model_contains ?: '—' }}</div>
            </td>
            <td class="py-2">
              <div>{{ $actionLabels[$rule->action] ?? $rule->action }}</div>
              <div class="text-gray-500">{{ $rule->action_value ?: '—' }}</div>
            </td>
            <td class="py-2">
              <div>prio {{ $rule->priority }} · hits {{ $rule->hit_count }}</div>
              <div class="text-gray-500">{{ $rule->is_active ? 'активно' : 'выключено' }}</div>
              @if(session('admin_role') === 'super')
              <form method="post" action="{{ route('admin.taxonomy.rules.delete', $rule->id) }}" class="mt-1">
                @csrf @method('DELETE')
                <button class="text-red-400 hover:text-red-300">удалить</button>
              </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="mt-3">{{ $rules->links() }}</div>
  </div>

  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 overflow-auto">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm text-gray-300 font-semibold">Очередь аномалий</h3>
      @if(session('admin_role') === 'super')
      <button
        onclick="approveAllHighConfidence('{{ csrf_token() }}')"
        id="approve-all-btn"
        class="px-3 py-1 rounded bg-green-700 hover:bg-green-600 text-white text-xs font-medium">
        ✅ Approve all (≥90%)
      </button>
      @endif
    </div>
    <table class="w-full text-xs text-gray-300">
      <thead class="text-gray-500 border-b border-gray-800">
        <tr>
          <th class="text-left py-2">Хвост</th>
          <th class="text-left py-2">Встречалось</th>
          <th class="text-left py-2">Подсказка</th>
          @if(session('admin_role') === 'super')
          <th class="text-left py-2 w-40">AI</th>
          @endif
        </tr>
      </thead>
      <tbody>
        @foreach($queue as $row)
          <tr class="border-b border-gray-800 align-top" id="qrow-{{ $row->id }}">
            <td class="py-2">
              <div class="text-white font-medium">{{ $row->unknown_tail }}</div>
              <div class="text-gray-500">{{ $row->make ?: '—' }} · {{ $row->reason ?: '—' }}</div>
              <div class="text-gray-600 italic">{{ $row->sample_model_raw ?: '' }}</div>
            </td>
            <td class="py-2">
              <div>{{ $row->seen_count }}</div>
              <div class="text-gray-500">{{ optional($row->last_seen_at)->diffForHumans() }}</div>
              <div class="text-gray-600">
                @if($row->status === 'new') новая
                @elseif($row->status === 'rule_created') правило создано
                @elseif($row->status === 'ai_reviewed') <span class="text-violet-400">AI проверено</span>
                @else игнор
                @endif
              </div>
            </td>
            <td class="py-2">
              <div id="qsugg-{{ $row->id }}">
                <div>{{ $row->suggested_action ? ($actionLabels[$row->suggested_action] ?? $row->suggested_action) : '—' }}</div>
                <div class="text-gray-500">{{ $row->suggested_value ?: '—' }} ({{ $row->suggestion_confidence !== null ? number_format($row->suggestion_confidence * 100, 0).'%' : '—' }})</div>
              </div>
              @if(session('admin_role') === 'super')
                <form method="post" action="{{ route('admin.taxonomy.queue.create-rule', $row->id) }}" class="mt-1 inline-block">
                  @csrf
                  <button class="text-indigo-400 hover:text-indigo-300">создать правило</button>
                </form>
                <form method="post" action="{{ route('admin.taxonomy.queue.update', $row->id) }}" class="mt-1 inline-block ml-2">
                  @csrf @method('PATCH')
                  <input type="hidden" name="status" value="ignored">
                  <button class="text-gray-400 hover:text-gray-200">игнор</button>
                </form>
              @endif
            </td>
            @if(session('admin_role') === 'super')
            <td class="py-2">
              <button
                onclick="aiClassify({{ $row->id }}, '{{ csrf_token() }}')"
                id="aibtn-{{ $row->id }}"
                class="px-2 py-1 rounded bg-violet-700 hover:bg-violet-600 text-white text-xs font-medium whitespace-nowrap">
                🤖 Спросить AI
              </button>
              <div id="airesult-{{ $row->id }}" class="mt-1 text-xs hidden"></div>
            </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="mt-3">{{ $queue->links() }}</div>
  </div>
</div>

<script>
async function aiClassify(id, csrf) {
  const btn = document.getElementById('aibtn-' + id);
  const box = document.getElementById('airesult-' + id);
  btn.disabled = true;
  btn.textContent = '⏳ Запрос...';
  box.className = 'mt-1 text-xs';
  box.textContent = '';

  try {
    const res = await fetch(`/admin/taxonomy/queue/${id}/ai-classify`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    });
    const data = await res.json();

    if (data.error) {
      box.textContent = '❌ ' + data.error;
      box.className = 'mt-1 text-xs text-red-400';
    } else {
      const typeLabels = { trim: 'комплектация', package: 'пакет опций', variant: 'вариант двигателя', body_style: 'тип кузова', model_suffix: 'часть модели', noise: 'шум' };
      const typeColors = { trim: 'text-green-400', package: 'text-blue-400', variant: 'text-yellow-400', body_style: 'text-orange-400', model_suffix: 'text-cyan-400', noise: 'text-gray-400' };
      const color = typeColors[data.type] || 'text-gray-300';
      const pct = Math.round((data.confidence || 0) * 100);
      const label = typeLabels[data.type] || data.type;
      const enPart = data.translation_en ? ` <span class="text-gray-400">(${data.translation_en})</span>` : '';
      box.innerHTML =
        `<div class="${color} font-semibold">${label} · ${pct}%</div>` +
        `<div class="text-white mt-0.5">"${data.value}"${enPart}</div>` +
        (data.context ? `<div class="text-gray-300 mt-1">${data.context}</div>` : '') +
        `<div class="text-gray-500 mt-1 italic text-xs">${data.reason || ''}</div>`;
      box.className = 'mt-1 text-xs max-w-xs';

      // Update suggestion cell
      const sugg = document.getElementById('qsugg-' + id);
      if (sugg) {
        sugg.innerHTML =
          `<div class="${color}">${data.action || data.type}</div>` +
          `<div class="text-gray-400">${data.value} (${pct}%)</div>`;
      }
    }
  } catch (e) {
    box.textContent = '❌ ' + e.message;
    box.className = 'mt-1 text-xs text-red-400';
  }

  btn.disabled = false;
  btn.textContent = '🤖 Спросить AI';
}

async function approveAllHighConfidence(csrf) {
  const btn = document.getElementById('approve-all-btn');
  if (!confirm('Создать правила для всех AI-reviewed записей с confidence ≥ 90%?')) return;
  btn.disabled = true;
  btn.textContent = '⏳ Создаю...';

  try {
    const res = await fetch('/admin/taxonomy/queue/approve-high-confidence', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    });
    const data = await res.json();
    if (data.created !== undefined) {
      btn.textContent = `✅ Создано: ${data.created} правил`;
      setTimeout(() => location.reload(), 1500);
    } else {
      btn.textContent = '❌ ' + (data.error || 'Ошибка');
      btn.disabled = false;
    }
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
}
</script>
@endsection
