@extends('admin.layout')
@section('title', 'Поиск лотов')

@section('content')

<form method="GET" action="{{ route('admin.lots-browse') }}" id="filter-form">

{{-- Search bar --}}
<div class="flex gap-3 mb-5">
  <input type="text" name="search" value="{{ request('search') }}"
         placeholder="ID лота, номер, VIN, марка, модель..."
         class="flex-1 bg-gray-900 border border-gray-800 rounded-lg px-4 py-2.5 text-sm text-white placeholder-gray-600
                focus:outline-none focus:border-blue-500">
  <button type="submit"
          class="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
    🔍 Найти
  </button>
  @if(request()->hasAny(['search','status','source','make','model','generation','trim','year_from','year_to','price_min','price_max','mileage_min','mileage_max','engine_min','engine_max','listed_from','listed_to','first_reg_from','first_reg_to','body_types','transmissions','fuels','drive_types','colors','has_accident','flood_history','owners_count','insurance_max','sort']))
    <a href="{{ route('admin.lots-browse') }}"
       class="px-4 py-2.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm transition">
      ✕ Сбросить
    </a>
  @endif
</div>

{{-- Filters panel --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-5 space-y-4"
     x-data="{ open: {{ request()->hasAny(['status','source','make','model','generation','trim','year_from','year_to','price_min','price_max','mileage_min','mileage_max','engine_min','engine_max','listed_from','listed_to','first_reg_from','first_reg_to','body_types','transmissions','fuels','drive_types','colors','has_accident','flood_history','owners_count','insurance_max']) ? 'true' : 'false' }} }">

  <div class="flex items-center justify-between">
    <span class="text-sm font-semibold text-white">Фильтры</span>
    <button type="button" @click="open = !open"
            class="text-xs text-gray-400 hover:text-white transition">
      <span x-text="open ? '▲ Скрыть' : '▼ Показать'">▼ Показать</span>
    </button>
  </div>

  <div x-show="open" x-cloak class="space-y-4">

    {{-- Row 1: Status + Source --}}
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Статус</label>
        <select id="filter-status" name="status" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="all" {{ request('status','all') === 'all' ? 'selected' : '' }}>Все</option>
          <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Активные</option>
          <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Неактивные</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Источник</label>
        <select id="filter-source" name="source" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Все</option>
          @foreach($sources as $src)
            <option value="{{ $src }}" {{ request('source') === $src ? 'selected' : '' }}>{{ $src }}</option>
          @endforeach
        </select>
      </div>
    </div>

    {{-- Row 3b: Listing and first registration dates --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Дата публикации объявления</label>
        <div class="flex gap-2">
          <input type="date" name="listed_from" value="{{ request('listed_from') }}"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="date" name="listed_to" value="{{ request('listed_to') }}"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Дата первой регистрации</label>
        <div class="flex gap-2">
          <input type="date" name="first_reg_from" value="{{ request('first_reg_from') }}"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="date" name="first_reg_to" value="{{ request('first_reg_to') }}"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
    </div>

    {{-- Row 1b: Taxonomy cascade Make / Model / Комплектация / Generation --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Марка</label>
        <select id="filter-make" name="make" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Все марки</option>
          @foreach(array_keys($makesModels) as $m)
            <option value="{{ $m }}" {{ request('make') === $m ? 'selected' : '' }}>{{ $m }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Модель</label>
        <select id="filter-model" name="model" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Все модели</option>
          @if(request('model'))
            <option value="{{ request('model') }}" selected>{{ request('model') }}</option>
          @endif
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Комплектация</label>
        <select id="filter-trim" name="trim" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Все</option>
          @if(request('trim'))
            <option value="{{ request('trim') }}" selected>{{ request('trim') }}</option>
          @endif
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Вариант модели</label>
        <select id="filter-generation" name="generation" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Любое</option>
          @foreach($generations as $g)
            <option value="{{ $g }}" {{ request('generation') === $g ? 'selected' : '' }}>{{ $g }}</option>
          @endforeach
        </select>
      </div>
    </div>

    {{-- Row 2: Year, Price, Mileage --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Год</label>
        <div class="flex gap-2">
          <input type="number" name="year_from" value="{{ request('year_from') }}" placeholder="от"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="number" name="year_to" value="{{ request('year_to') }}" placeholder="до"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Цена (₩)</label>
        <div class="flex gap-2">
          <input type="number" name="price_min" value="{{ request('price_min') }}" placeholder="от"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="number" name="price_max" value="{{ request('price_max') }}" placeholder="до"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Пробег (км)</label>
        <div class="flex gap-2">
          <input type="number" name="mileage_min" value="{{ request('mileage_min') }}" placeholder="от"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="number" name="mileage_max" value="{{ request('mileage_max') }}" placeholder="до"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
    </div>

    {{-- Row 3: Engine, Owners, Insurance --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Объём двигателя (л)</label>
        <div class="flex gap-2">
          <input type="number" name="engine_min" value="{{ request('engine_min') }}" placeholder="от" step="0.1"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <input type="number" name="engine_max" value="{{ request('engine_max') }}" placeholder="до" step="0.1"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Владельцев макс.</label>
        <input type="number" name="owners_count" value="{{ request('owners_count') }}" placeholder="напр. 2" min="0"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Страховых выплат макс.</label>
        <input type="number" name="insurance_max" value="{{ request('insurance_max') }}" placeholder="напр. 1" min="0"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
      </div>
    </div>

    {{-- Row 4: Multi-selects --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Тип кузова</label>
        <select id="filter-body-types" name="body_types[]" multiple size="4"
                class="js-select2-multi w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($bodyTypes as $bt)
            <option value="{{ $bt }}" {{ in_array($bt, (array)request('body_types', [])) ? 'selected' : '' }}>{{ \App\Support\Taxonomy\TaxonomyLocalizer::label('body_type', (string) $bt, 'ru') }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">КПП</label>
        <select id="filter-transmissions" name="transmissions[]" multiple size="4"
                class="js-select2-multi w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($transList as $tr)
            <option value="{{ $tr }}" {{ in_array($tr, (array)request('transmissions', [])) ? 'selected' : '' }}>{{ \App\Support\Taxonomy\TaxonomyLocalizer::label('transmission', (string) $tr, 'ru') }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Топливо</label>
        <select id="filter-fuels" name="fuels[]" multiple size="4"
                class="js-select2-multi w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($fuelList as $fu)
            <option value="{{ $fu }}" {{ in_array($fu, (array)request('fuels', [])) ? 'selected' : '' }}>{{ \App\Support\Taxonomy\TaxonomyLocalizer::label('fuel', (string) $fu, 'ru') }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Привод</label>
        <select id="filter-drive-types" name="drive_types[]" multiple size="4"
                class="js-select2-multi w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($driveList as $dr)
            <option value="{{ $dr }}" {{ in_array($dr, (array)request('drive_types', [])) ? 'selected' : '' }}>{{ \App\Support\Taxonomy\TaxonomyLocalizer::label('drive_type', (string) $dr, 'ru') }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Цвет</label>
        <select id="filter-colors" name="colors[]" multiple size="4"
                class="js-select2-multi w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($colorList as $co)
            <option value="{{ $co }}" {{ in_array($co, (array)request('colors', [])) ? 'selected' : '' }}>{{ $co }}</option>
          @endforeach
        </select>
      </div>
    </div>

    {{-- Row 5: Booleans + Sort + Per page --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Аварийная история</label>
        <select id="filter-has-accident" name="has_accident" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Любая</option>
          <option value="0" {{ request('has_accident') === '0' ? 'selected' : '' }}>Без ДТП</option>
          <option value="1" {{ request('has_accident') === '1' ? 'selected' : '' }}>Были ДТП</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Затопление</label>
        <select id="filter-flood-history" name="flood_history" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Любая</option>
          <option value="0" {{ request('flood_history') === '0' ? 'selected' : '' }}>Нет</option>
          <option value="1" {{ request('flood_history') === '1' ? 'selected' : '' }}>Да</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Сортировка</label>
        <select id="filter-sort" name="sort" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="newest"       {{ request('sort','newest') === 'newest'       ? 'selected' : '' }}>Новые</option>
          <option value="oldest"       {{ request('sort') === 'oldest'       ? 'selected' : '' }}>Старые</option>
          <option value="price_asc"    {{ request('sort') === 'price_asc'    ? 'selected' : '' }}>Цена ↑</option>
          <option value="price_desc"   {{ request('sort') === 'price_desc'   ? 'selected' : '' }}>Цена ↓</option>
          <option value="mileage_asc"  {{ request('sort') === 'mileage_asc'  ? 'selected' : '' }}>Пробег ↑</option>
          <option value="mileage_desc" {{ request('sort') === 'mileage_desc' ? 'selected' : '' }}>Пробег ↓</option>
          <option value="year_asc"     {{ request('sort') === 'year_asc'     ? 'selected' : '' }}>Год ↑</option>
          <option value="year_desc"    {{ request('sort') === 'year_desc'    ? 'selected' : '' }}>Год ↓</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">На странице</label>
        <select id="filter-per-page" name="per_page" class="js-select2 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          @foreach([20, 50, 100, 200] as $pp)
            <option value="{{ $pp }}" {{ (int)request('per_page', 50) === $pp ? 'selected' : '' }}>{{ $pp }}</option>
          @endforeach
        </select>
      </div>
      <div class="flex items-end">
        <button type="submit"
                class="w-full px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
          Применить
        </button>
      </div>
    </div>

  </div>
</div>

</form>

{{-- Results --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800">
    <span class="text-sm font-semibold text-white">
      Найдено: <span class="text-blue-400">{{ number_format($lots->total()) }}</span>
    </span>
    <span class="text-xs text-gray-500">
      Страница {{ $lots->currentPage() }} из {{ $lots->lastPage() }}
    </span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-xs">
      <thead class="bg-gray-900/60 text-gray-500 uppercase tracking-wider">
        <tr>
          <th class="px-4 py-3 text-left">ID лота</th>
          <th class="px-4 py-3 text-left">Статус</th>
          <th class="px-4 py-3 text-left">Марка / Модель</th>
          <th class="px-4 py-3 text-left">Год</th>
          <th class="px-4 py-3 text-right">Цена ₩</th>
          <th class="px-4 py-3 text-right">Пробег</th>
          <th class="px-4 py-3 text-left">КПП</th>
          <th class="px-4 py-3 text-left">Топливо</th>
          <th class="px-4 py-3 text-left">Кузов</th>
          <th class="px-4 py-3 text-left">ДТП</th>
          <th class="px-4 py-3 text-left">VIN</th>
          <th class="px-4 py-3 text-left">Обновлено</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-800/60">
        @forelse($lots as $lot)
          @php
            $lotUrl = match($lot->source) {
              'encar' => 'https://www.encar.com/dc/dc_cardetailview.do?carid=' . $lot->id,
              default => null,
            };
          @endphp
          <tr class="hover:bg-gray-800/30 {{ $lot->is_active ? '' : 'opacity-50' }}">
            <td class="px-4 py-2.5 font-mono">
              @if($lotUrl)
                <a href="{{ $lotUrl }}" target="_blank"
                   class="text-blue-400 hover:text-blue-300">{{ $lot->id }}</a>
              @else
                <span class="text-gray-400">{{ $lot->id }}</span>
              @endif
              <span class="ml-1 text-gray-600">{{ $lot->source }}</span>
            </td>
            <td class="px-4 py-2.5">
              @if($lot->is_active)
                <span class="px-2 py-0.5 rounded-full text-xs bg-green-900/60 text-green-400">актив</span>
              @else
                <span class="px-2 py-0.5 rounded-full text-xs bg-gray-800 text-gray-500">снят</span>
              @endif
            </td>
            <td class="px-4 py-2.5">
              <div class="text-white font-semibold">{{ $lot->make }} {{ $lot->model }}</div>
              @if($lot->model_en)<div class="text-gray-400 mt-0.5">{{ $lot->model_en }}</div>@endif
              @if($lot->trim)<div class="text-gray-500 mt-0.5">{{ $lot->trim }}</div>@endif
            </td>
            <td class="px-4 py-2.5 text-gray-300">{{ $lot->year }}</td>
            <td class="px-4 py-2.5 text-right text-yellow-400 font-semibold">
              {{ $lot->price ? '₩' . number_format($lot->price) : '—' }}
            </td>
            <td class="px-4 py-2.5 text-right text-gray-300">
              {{ $lot->mileage ? number_format($lot->mileage) . ' km' : '—' }}
            </td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->transmission ? \App\Support\Taxonomy\TaxonomyLocalizer::label('transmission', (string) $lot->transmission, 'ru') : '—' }}</td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->fuel ? \App\Support\Taxonomy\TaxonomyLocalizer::label('fuel', (string) $lot->fuel, 'ru') : '—' }}</td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->body_type ? \App\Support\Taxonomy\TaxonomyLocalizer::label('body_type', (string) $lot->body_type, 'ru') : '—' }}</td>
            <td class="px-4 py-2.5">
              @if($lot->has_accident === null)
                <span class="text-gray-600">?</span>
              @elseif($lot->has_accident)
                <span class="text-red-400">⚠ ДТП</span>
              @else
                <span class="text-green-500">✓</span>
              @endif
            </td>
            <td class="px-4 py-2.5 font-mono text-gray-500 text-[10px]">{{ $lot->vin ?? '—' }}</td>
            <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap">
              {{ $lot->parsed_at ? \Carbon\Carbon::parse($lot->parsed_at)->diffForHumans() : '—' }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="12" class="px-5 py-12 text-center text-gray-600">Лотов не найдено</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($lots->hasPages())
    <div class="px-5 py-4 border-t border-gray-800">
      {{ $lots->appends(request()->query())->links('pagination::tailwind') }}
    </div>
  @endif
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
  .select2-container { width: 100% !important; }
  .select2-container--default .select2-selection--single,
  .select2-container--default .select2-selection--multiple {
    background-color: rgb(31 41 55) !important;
    border: 1px solid rgb(55 65 81) !important;
    border-radius: 0.5rem;
    min-height: 38px;
    color: #fff !important;
  }
  .select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #fff !important;
    line-height: 36px;
  }
  .select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: #9ca3af !important;
  }
  .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
  .select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: rgb(30 58 138) !important;
    border: none;
    color: #fff !important;
  }
  .select2-dropdown {
    background-color: rgb(17 24 39) !important;
    border-color: rgb(55 65 81) !important;
    color: #fff !important;
  }
  .select2-results__option { color: #d1d5db !important; }
  .select2-search--dropdown .select2-search__field {
    background: rgb(31 41 55) !important;
    border: 1px solid rgb(55 65 81) !important;
    color: #fff !important;
  }
  .select2-container--default .select2-selection--single .select2-selection__arrow b {
    border-color: #9ca3af transparent transparent transparent !important;
  }
  .select2-container--default .select2-selection--single .select2-selection__clear {
    color: #9ca3af !important;
  }
  .select2-results__option--highlighted[aria-selected] {
    background-color: rgb(37 99 235) !important;
    color: #fff !important;
  }
</style>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
  const getVal = (id) => document.getElementById(id)?.value ?? '';
  const getVals = (id) => Array.from(document.getElementById(id)?.selectedOptions ?? []).map(o => o.value);

  const normalizeOptionItems = (items = []) => {
    return (items ?? []).map((item) => {
      if (item && typeof item === 'object' && 'value' in item) {
        const value = String(item.value ?? '').trim();
        const label = String(item.label ?? value).trim();
        return { value, label: label || value };
      }
      const value = String(item ?? '').trim();
      return { value, label: value };
    }).filter(o => o.value !== '');
  };

  const setSingleOptions = (id, options, placeholder, selected) => {
    const el = document.getElementById(id);
    if (!el) return;
    const list = normalizeOptionItems(options);
    const values = new Set(list.map(o => o.value));
    const allowed = values.has(selected) ? selected : '';
    el.innerHTML = '';
    el.appendChild(new Option(placeholder, ''));
    list.forEach(o => el.appendChild(new Option(o.label, o.value, false, o.value === allowed)));
    $(el).val(allowed).trigger('change');
  };

  const setMultiOptions = (id, options, selectedValues) => {
    const el = document.getElementById(id);
    if (!el) return;
    const list = normalizeOptionItems(options);
    const values = new Set(list.map(o => o.value));
    const selected = (selectedValues ?? []).filter(v => values.has(v));
    el.innerHTML = '';
    list.forEach(o => el.appendChild(new Option(o.label, o.value, false, selected.includes(o.value))));
    $(el).val(selected).trigger('change');
  };

  $(function () {
    $('.js-select2').select2({
      width: '100%',
      allowClear: true,
      placeholder: 'Выберите значение'
    });
    $('.js-select2-multi').select2({
      width: '100%',
      closeOnSelect: false,
      placeholder: 'Выберите значения'
    });

    let syncing = false;

    async function refreshContext(depth = 0) {
      const before = {
        make: getVal('filter-make'),
        model: getVal('filter-model'),
        trim: getVal('filter-trim'),
        generation: getVal('filter-generation'),
        body: getVals('filter-body-types'),
        trans: getVals('filter-transmissions'),
        fuel: getVals('filter-fuels'),
        drive: getVals('filter-drive-types'),
        colors: getVals('filter-colors'),
      };

      const params = new URLSearchParams({
        locale:     'ru',
        status:     getVal('filter-status')     || 'all',
        source:     getVal('filter-source')     || '',
        make:       before.make                 || '',
        model:      before.model                || '',
        trim:       before.trim                 || '',
        generation: before.generation           || '',
        badge:      getVal('filter-badge')      || '',
      });

      try {
        const res = await fetch(`/api/filters/context?${params.toString()}`);
        const json = await res.json();
        const data = json?.data ?? {};
        syncing = true;

        setSingleOptions('filter-make', data.makeOptions ?? data.makes ?? [], 'Все марки', before.make);
        setSingleOptions('filter-model', data.modelOptions ?? data.models ?? [], 'Все модели', before.model);
        setSingleOptions('filter-trim', data.trimOptions ?? data.trims ?? [], 'Все комплектации', before.trim);
        setSingleOptions('filter-generation', data.generationOptions ?? data.generations ?? [], 'Любое поколение', before.generation);

        setMultiOptions('filter-body-types', data.bodyTypeOptions ?? data.bodyTypes ?? [], before.body);
        setMultiOptions('filter-transmissions', data.transmissionOptions ?? data.transmissions ?? [], before.trans);
        setMultiOptions('filter-fuels', data.fuelTypeOptions ?? data.fuelTypes ?? [], before.fuel);
        setMultiOptions('filter-drive-types', data.driveTypeOptions ?? data.driveTypes ?? [], before.drive);
        setMultiOptions('filter-colors', data.colorOptions ?? data.colors ?? [], before.colors);

        const after = {
          make: getVal('filter-make'),
          model: getVal('filter-model'),
          trim: getVal('filter-trim'),
          generation: getVal('filter-generation'),
        };

        const shifted =
          after.make !== before.make ||
          after.model !== before.model ||
          after.trim !== before.trim ||
          after.generation !== before.generation;

        if (shifted && depth < 2) {
          syncing = false;
          await refreshContext(depth + 1);
          return;
        }
      } catch (e) {
      } finally {
        syncing = false;
      }
    }

    const onTaxChange = (id, clearIds = []) => {
      $(`#${id}`).on('change', async function () {
        if (syncing) return;
        syncing = true;
        clearIds.forEach(cid => {
          const el = document.getElementById(cid);
          if (!el) return;
          if (el.multiple) {
            $(el).val([]).trigger('change.select2');
          } else {
            $(el).val('').trigger('change.select2');
          }
        });
        syncing = false;
        await refreshContext();
      });
    };

    onTaxChange('filter-status');
    onTaxChange('filter-source');
    onTaxChange('filter-make',       ['filter-model', 'filter-trim', 'filter-generation', 'filter-body-types', 'filter-transmissions', 'filter-fuels', 'filter-drive-types', 'filter-colors']);
    onTaxChange('filter-model',      ['filter-trim', 'filter-generation', 'filter-body-types', 'filter-transmissions', 'filter-fuels', 'filter-drive-types', 'filter-colors']);
    onTaxChange('filter-generation', ['filter-trim', 'filter-body-types', 'filter-transmissions', 'filter-fuels', 'filter-drive-types', 'filter-colors']);
    onTaxChange('filter-trim',       ['filter-body-types', 'filter-transmissions', 'filter-fuels', 'filter-drive-types', 'filter-colors']);

    refreshContext();
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
@endsection
