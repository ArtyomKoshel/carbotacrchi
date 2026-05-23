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
  @if(request()->hasAny(['search','status','source','make','model','year_from','year_to','price_min','price_max','mileage_min','mileage_max','engine_min','engine_max','body_types','transmissions','fuels','drive_types','colors','has_accident','flood_history','owners_count','insurance_max','sort']))
    <a href="{{ route('admin.lots-browse') }}"
       class="px-4 py-2.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm transition">
      ✕ Сбросить
    </a>
  @endif
</div>

{{-- Filters panel --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-5 space-y-4"
     x-data="{ open: {{ request()->hasAny(['status','source','make','model','year_from','year_to','price_min','price_max','mileage_min','mileage_max','engine_min','engine_max','body_types','transmissions','fuels','drive_types','colors','has_accident','flood_history','owners_count','insurance_max']) ? 'true' : 'false' }} }">

  <div class="flex items-center justify-between">
    <span class="text-sm font-semibold text-white">Фильтры</span>
    <button type="button" @click="open = !open"
            class="text-xs text-gray-400 hover:text-white transition">
      <span x-text="open ? '▲ Скрыть' : '▼ Показать'">▼ Показать</span>
    </button>
  </div>

  <div x-show="open" x-cloak class="space-y-4">

    {{-- Row 1: Status, Source, Make, Model --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Статус</label>
        <select name="status" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="all" {{ request('status','all') === 'all' ? 'selected' : '' }}>Все</option>
          <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Активные</option>
          <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Неактивные</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Источник</label>
        <select name="source" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Все</option>
          @foreach($sources as $src)
            <option value="{{ $src }}" {{ request('source') === $src ? 'selected' : '' }}>{{ $src }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Марка</label>
        <input type="text" name="make" value="{{ request('make') }}" list="makes-list"
               placeholder="напр. Hyundai"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        <datalist id="makes-list">
          @foreach($makes as $m)<option value="{{ $m }}">@endforeach
        </datalist>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Модель</label>
        <input type="text" name="model" value="{{ request('model') }}"
               placeholder="напр. Tucson"
               class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
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
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Тип кузова</label>
        <select name="body_types[]" multiple size="4"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($bodyTypes as $bt)
            <option value="{{ $bt }}" {{ in_array($bt, (array)request('body_types', [])) ? 'selected' : '' }}>{{ $bt }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">КПП</label>
        <select name="transmissions[]" multiple size="4"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($transList as $tr)
            <option value="{{ $tr }}" {{ in_array($tr, (array)request('transmissions', [])) ? 'selected' : '' }}>{{ $tr }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Топливо</label>
        <select name="fuels[]" multiple size="4"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($fuelList as $fu)
            <option value="{{ $fu }}" {{ in_array($fu, (array)request('fuels', [])) ? 'selected' : '' }}>{{ $fu }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Привод</label>
        <select name="drive_types[]" multiple size="4"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white">
          @foreach($driveList as $dr)
            <option value="{{ $dr }}" {{ in_array($dr, (array)request('drive_types', [])) ? 'selected' : '' }}>{{ $dr }}</option>
          @endforeach
        </select>
      </div>
    </div>

    {{-- Row 5: Booleans + Sort + Per page --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Аварийная история</label>
        <select name="has_accident" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Любая</option>
          <option value="0" {{ request('has_accident') === '0' ? 'selected' : '' }}>Без ДТП</option>
          <option value="1" {{ request('has_accident') === '1' ? 'selected' : '' }}>Были ДТП</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Затопление</label>
        <select name="flood_history" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">Любая</option>
          <option value="0" {{ request('flood_history') === '0' ? 'selected' : '' }}>Нет</option>
          <option value="1" {{ request('flood_history') === '1' ? 'selected' : '' }}>Да</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Сортировка</label>
        <select name="sort" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
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
        <select name="per_page" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
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
              @if($lot->trim)<div class="text-gray-500 mt-0.5">{{ $lot->trim }}</div>@endif
            </td>
            <td class="px-4 py-2.5 text-gray-300">{{ $lot->year }}</td>
            <td class="px-4 py-2.5 text-right text-yellow-400 font-semibold">
              {{ $lot->price ? '₩' . number_format($lot->price) : '—' }}
            </td>
            <td class="px-4 py-2.5 text-right text-gray-300">
              {{ $lot->mileage ? number_format($lot->mileage) . ' km' : '—' }}
            </td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->transmission ?? '—' }}</td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->fuel ?? '—' }}</td>
            <td class="px-4 py-2.5 text-gray-400">{{ $lot->body_type ?? '—' }}</td>
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

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
@endsection
