@extends('admin.layout')
@section('title', 'Бот-фильтры')

@section('content')

@php
  $ui = \App\Support\AdminUiLabels::class;
@endphp

@php
  $categoryLabels = [
    'identity' => 'Идентификация',
    'price' => 'Цена',
    'condition' => 'Состояние',
    'specs' => 'Характеристики',
    'legal' => 'Юридическое',
    'sales' => 'Продажи',
    'other' => 'Прочее',
  ];

  $dtypeLabels = [
    'string' => 'текст',
    'int' => 'число',
    'float' => 'дробное',
    'bool' => 'да/нет',
    'enum' => 'список',
    'date' => 'дата',
  ];

  $totalCount = $settings->count();
  $enabledCount = $settings->where('enabled', true)->count();
  $cardCount = $settings->where('display_in_card', true)->count();
@endphp

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  <p class="font-semibold mb-1">Ошибки валидации:</p>
  <ul class="list-disc list-inside">
    @foreach($errors->all() as $error)
      <li>{{ $error }}</li>
    @endforeach
  </ul>
</div>
@endif

<p class="text-sm text-gray-500 mb-6">
  Тут настраивается, какие поля бот использует в поиске и как расширяет диапазон значений.
  Изменения применяются сразу после сохранения.
</p>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-sm font-semibold text-white">Настройки фильтров бота</div>
        <div class="text-xs text-gray-500 mt-1">Поля, допуски и отображение в карточке</div>
        <div class="text-[11px] text-gray-600 mt-1">"В поиске" = участвует и в AI-парсинге, и в фильтрации БД</div>
      </div>

      <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="px-2 py-1 rounded bg-gray-800 text-gray-300">Всего: {{ $totalCount }}</span>
        <span class="px-2 py-1 rounded bg-green-900/40 text-green-300">В поиске: {{ $enabledCount }}</span>
        <span class="px-2 py-1 rounded bg-blue-900/40 text-blue-300">В карточке: {{ $cardCount }}</span>
      </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <input id="bf-search" type="text" placeholder="Поиск по полю, описанию, типу..."
             class="w-72 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500">

      <label class="inline-flex items-center gap-2 text-xs text-gray-300 px-2 py-1 rounded bg-gray-800">
        <input id="bf-only-enabled" type="checkbox" class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-600">
        Только включенные
      </label>

      <button type="button" id="bf-enable-all"
              class="px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs transition">
        Включить все
      </button>
      <button type="button" id="bf-disable-all"
              class="px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs transition">
        Выключить все
      </button>

      <div class="ml-auto flex items-center gap-2">
        <form method="POST" action="{{ route('admin.bot-filters.reset') }}"
              onsubmit="return confirm('Сбросить настройки к дефолтным значениям?')">
          @csrf
          <button type="submit"
                  class="px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm transition">
            Сбросить
          </button>
        </form>
        <button type="submit" form="bot-filters-form"
                class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
          Сохранить
        </button>
      </div>
    </div>

    <div class="mt-4 p-3 rounded-lg border border-gray-800 bg-gray-950/60">
      <div class="text-xs text-gray-500 mb-2 uppercase tracking-wider">Тест парсинга запроса</div>
      <div class="flex flex-wrap items-center gap-2">
        <input id="bf-preview-text" type="text"
               placeholder="Например: BMW 5 2020 пробег 100000 дизель"
               class="flex-1 min-w-[320px] bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white placeholder-gray-500">
        <button type="button" id="bf-preview-run"
                class="px-3 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-200 text-sm transition">
          Проверить
        </button>
      </div>
      <div id="bf-preview-result" class="mt-3 text-xs text-gray-300 whitespace-pre-wrap"></div>
    </div>
  </div>

  <form id="bot-filters-form" method="POST" action="{{ route('admin.bot-filters.update') }}">
    @csrf

    @forelse($categories as $category => $rows)
      @php
        $catKey = $category ?: 'other';
        $catLabel = $categoryLabels[$catKey] ?? ucfirst($catKey);
        $catTotal = $rows->count();
        $catEnabled = $rows->where('enabled', true)->count();
      @endphp

      <div class="px-5 py-3 border-b border-gray-800/70 bg-gray-900/70 flex items-center justify-between">
        <div class="text-xs uppercase tracking-wider text-gray-500 font-semibold">{{ $catLabel }}</div>
        <div class="text-[11px] text-gray-600">{{ $catEnabled }} / {{ $catTotal }} включено</div>
      </div>

      <div class="divide-y divide-gray-800/60">
        @foreach($rows as $setting)
          @php
            $supportsTolerance = in_array($setting->dtype, ['int', 'float', 'date'], true);
            $tolType = $setting->tolerance_type ?: 'none';
            $tolValue = $setting->tolerance_value;
            $tolDisplay = $tolType === 'percentage' && $tolValue !== null ? $tolValue * 100 : $tolValue;
            $searchText = mb_strtolower(trim(implode(' ', [
              $setting->field_name,
              $setting->field_label,
              $setting->description,
              $setting->dtype,
              $catLabel,
            ])));
          @endphp

          <div class="px-5 py-3 grid grid-cols-12 gap-3 items-center text-sm"
               data-filter-row
               data-field-name="{{ $setting->field_name }}"
               data-dtype="{{ $setting->dtype }}"
               data-search="{{ e($searchText) }}">
            <div class="col-span-4 min-w-0">
              <div class="text-white font-medium truncate">{{ $setting->field_label ?: $ui::field($setting->field_name) }}</div>
              <div class="text-[11px] text-gray-500 truncate">
                <span class="font-mono text-gray-400">{{ $setting->field_name }}</span>
                @if($setting->description)
                  · {{ $setting->description }}
                @endif
              </div>
            </div>

            <div class="col-span-1 text-xs">
              <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-300">{{ $dtypeLabels[$setting->dtype] ?? $setting->dtype }}</span>
            </div>

            <div class="col-span-2">
              <label class="inline-flex items-center gap-2 text-xs text-gray-300">
                <input type="checkbox" name="fields[{{ $setting->id }}][enabled]" value="1"
                       {{ $setting->enabled ? 'checked' : '' }}
                       data-enabled-input
                       class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-600">
                В поиске
              </label>
            </div>

            <div class="col-span-3 flex items-center gap-2">
              @if($supportsTolerance)
                <select name="fields[{{ $setting->id }}][tolerance_type]"
                        data-tolerance-type
                        class="bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white w-28">
                  <option value="none" {{ $tolType === 'none' ? 'selected' : '' }}>без допуска</option>
                  <option value="absolute" {{ $tolType === 'absolute' ? 'selected' : '' }}>± число</option>
                  <option value="percentage" {{ $tolType === 'percentage' ? 'selected' : '' }}>± %</option>
                </select>
                <input type="number" step="0.0001" min="0"
                       name="fields[{{ $setting->id }}][tolerance_value]"
                       value="{{ $tolDisplay !== null ? $tolDisplay : '' }}"
                       data-tolerance-value
                       class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white"
                       placeholder="значение">
                <span class="text-[11px] text-gray-600" data-tolerance-help></span>
                <span class="text-[11px] text-blue-300" data-tolerance-preview></span>
              @else
                <input type="hidden" name="fields[{{ $setting->id }}][tolerance_type]" value="none">
                <input type="hidden" name="fields[{{ $setting->id }}][tolerance_value]" value="">
                <span class="text-xs text-gray-600">для этого типа недоступно</span>
              @endif
            </div>

            <div class="col-span-2">
              <label class="inline-flex items-center gap-2 text-xs text-gray-300">
                <input type="checkbox" name="fields[{{ $setting->id }}][display_in_card]" value="1"
                       {{ $setting->display_in_card ? 'checked' : '' }}
                       class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-600">
                В карточке
              </label>
            </div>
          </div>
        @endforeach
      </div>
    @empty
      <div class="px-5 py-10 text-center text-gray-500 text-sm">Настройки не найдены.</div>
    @endforelse

    <div class="px-5 py-4 border-t border-gray-800 flex items-center justify-between">
      <div class="text-xs text-gray-600">Подсказка: для процента вводи число в человеческом виде (например, 15 = ±15%).</div>
      <button type="submit"
              class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
        Сохранить настройки
      </button>
    </div>
  </form>
</div>

<script>
(() => {
  const rows = Array.from(document.querySelectorAll('[data-filter-row]'));
  const searchInput = document.getElementById('bf-search');
  const onlyEnabled = document.getElementById('bf-only-enabled');
  const enableAllBtn = document.getElementById('bf-enable-all');
  const disableAllBtn = document.getElementById('bf-disable-all');
  const previewInput = document.getElementById('bf-preview-text');
  const previewRunBtn = document.getElementById('bf-preview-run');
  const previewResult = document.getElementById('bf-preview-result');

  function normalize(value) {
    return (value || '').toString().toLowerCase();
  }

  function syncTolerance(row) {
    const typeEl = row.querySelector('[data-tolerance-type]');
    const valueEl = row.querySelector('[data-tolerance-value]');
    const helpEl = row.querySelector('[data-tolerance-help]');
    const previewEl = row.querySelector('[data-tolerance-preview]');
    if (!typeEl || !valueEl) return;

    const type = typeEl.value;
    const disabled = type === 'none';

    valueEl.disabled = disabled;
    valueEl.classList.toggle('opacity-50', disabled);

    if (disabled) {
      valueEl.value = '';
      valueEl.placeholder = '—';
      if (helpEl) helpEl.textContent = 'допуск отключен';
      if (previewEl) previewEl.textContent = '';
      return;
    }

    if (type === 'percentage') {
      valueEl.step = '0.01';
      valueEl.placeholder = 'например 15';
      if (helpEl) helpEl.textContent = 'в процентах';
    } else {
      valueEl.step = '1';
      valueEl.placeholder = 'например 10000';
      if (helpEl) helpEl.textContent = 'абсолютное значение';
    }

    updateTolerancePreview(row);
  }

  function getSampleValue(row) {
    const field = normalize(row.dataset.fieldName);
    const dtype = normalize(row.dataset.dtype);

    if (field === 'mileage') return 100000;
    if (field === 'price') return 20000000;
    if (field === 'year') return 2020;
    if (field === 'engine_volume') return 2.0;
    if (field === 'insurance_count' || field === 'owners_count') return 1;

    if (dtype === 'float') return 1.0;
    if (dtype === 'int' || dtype === 'date') return 100;
    return null;
  }

  function formatNum(value, dtype) {
    if (dtype === 'float') {
      return Number(value).toFixed(2).replace(/\.00$/, '');
    }
    return Math.round(value).toLocaleString('ru-RU');
  }

  function updateTolerancePreview(row) {
    const typeEl = row.querySelector('[data-tolerance-type]');
    const valueEl = row.querySelector('[data-tolerance-value]');
    const previewEl = row.querySelector('[data-tolerance-preview]');
    if (!typeEl || !valueEl || !previewEl) return;

    const type = typeEl.value;
    const dtype = normalize(row.dataset.dtype);
    const sample = getSampleValue(row);
    const raw = Number(valueEl.value);

    if (type === 'none' || sample === null || Number.isNaN(raw) || raw < 0) {
      previewEl.textContent = '';
      return;
    }

    let delta = raw;
    if (type === 'percentage') {
      delta = delta > 1 ? delta / 100 : delta;
    }

    let minValue;
    let maxValue;

    if (type === 'absolute') {
      minValue = Math.max(0, sample - delta);
      maxValue = sample + delta;
    } else {
      minValue = Math.max(0, sample * (1 - delta));
      maxValue = sample * (1 + delta);
    }

    const from = formatNum(minValue, dtype);
    const to = formatNum(maxValue, dtype);
    const sampleText = formatNum(sample, dtype);
    previewEl.textContent = `пример: ${sampleText} → ${from}–${to}`;
  }

  function applyFilters() {
    const q = normalize(searchInput?.value);
    const only = !!onlyEnabled?.checked;

    rows.forEach((row) => {
      const enabledInput = row.querySelector('[data-enabled-input]');
      const isEnabled = !!enabledInput?.checked;
      const hay = normalize(row.dataset.search);
      const queryMatch = !q || hay.includes(q);
      const enabledMatch = !only || isEnabled;
      row.classList.toggle('hidden', !(queryMatch && enabledMatch));
    });
  }

  rows.forEach((row) => {
    const typeEl = row.querySelector('[data-tolerance-type]');
    const enabledInput = row.querySelector('[data-enabled-input]');
    if (typeEl) {
      typeEl.addEventListener('change', () => syncTolerance(row));
      syncTolerance(row);
    }
    const valueEl = row.querySelector('[data-tolerance-value]');
    if (valueEl) {
      valueEl.addEventListener('input', () => updateTolerancePreview(row));
    }
    if (enabledInput) {
      enabledInput.addEventListener('change', applyFilters);
    }
  });

  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (onlyEnabled) onlyEnabled.addEventListener('change', applyFilters);

  if (enableAllBtn) {
    enableAllBtn.addEventListener('click', () => {
      rows.forEach((row) => {
        const enabledInput = row.querySelector('[data-enabled-input]');
        if (enabledInput) enabledInput.checked = true;
      });
      applyFilters();
    });
  }

  if (disableAllBtn) {
    disableAllBtn.addEventListener('click', () => {
      rows.forEach((row) => {
        const enabledInput = row.querySelector('[data-enabled-input]');
        if (enabledInput) enabledInput.checked = false;
      });
      applyFilters();
    });
  }

  async function runPreview() {
    if (!previewInput || !previewResult) return;
    const text = (previewInput.value || '').trim();
    if (!text) {
      previewResult.textContent = 'Введите текст запроса';
      return;
    }

    previewResult.textContent = 'Проверяю...';

    try {
      const res = await fetch("{{ route('admin.bot-filters.preview') }}", {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': "{{ csrf_token() }}",
        },
        body: JSON.stringify({ text }),
      });

      const json = await res.json();
      if (!res.ok || !json?.ok) {
        previewResult.textContent = `Ошибка: ${json?.error || 'неизвестная ошибка'}`;
        return;
      }

      const d = json.data || {};
      const pretty = [
        `режим: ${d.mode || 'неизвестно'}`,
        `описание: ${d.description || '-'}`,
        `допуски: ${d.toleranceNote || '-'}`,
        '',
        'исходный разбор:',
        JSON.stringify(d.parsed || {}, null, 2),
        '',
        'разбор с допусками:',
        JSON.stringify(d.tolerant || {}, null, 2),
      ].join('\n');

      previewResult.textContent = pretty;
    } catch (e) {
      previewResult.textContent = `Ошибка сети: ${e?.message || e}`;
    }
  }

  if (previewRunBtn) {
    previewRunBtn.addEventListener('click', runPreview);
  }
  if (previewInput) {
    previewInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        runPreview();
      }
    });
  }

  applyFilters();
})();
</script>

@endsection
