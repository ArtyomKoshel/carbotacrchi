@extends('admin.layout')
@section('title', 'Field Accuracy')

@section('content')
@php
$pct = fn($count, $total) => $total > 0 ? round($count / $total * 100) : 0;
$barColor = fn($p) => $p >= 80 ? 'bg-emerald-500' : ($p >= 40 ? 'bg-amber-400' : 'bg-red-500');
$badge    = fn($p) => $p >= 80 ? 'text-emerald-400' : ($p >= 40 ? 'text-amber-400' : 'text-red-400');
@endphp

{{-- Header row: title + refresh button + cache age --}}
<div class="flex items-center justify-between mb-4">
  <h2 class="text-white font-semibold text-lg">Field Accuracy</h2>
  <div class="flex items-center gap-3">
    @if($cachedAt ?? null)
      @php $ageMin = round((time() - $cachedAt) / 60) @endphp
      <span class="text-xs text-gray-500">cached {{ $ageMin < 1 ? 'just now' : "{$ageMin}m ago" }}</span>
    @endif
    <form method="POST" action="{{ route('admin.accuracy.refresh') }}">
      @csrf
      <button type="submit" class="px-3 py-1.5 rounded-lg text-xs bg-gray-800 text-gray-300 hover:bg-gray-700 hover:text-white transition">
        ↻ Refresh
      </button>
    </form>
  </div>
</div>

@if(session('success'))
  <div class="mb-4 bg-green-900/40 border border-green-700 rounded-xl px-4 py-2 text-sm text-green-300">
    {{ session('success') }}
  </div>
@endif

{{-- SQL errors (shown only when a query fails) --}}
@if(!empty($errors))
  <div class="mb-6 bg-red-900/50 border border-red-700 rounded-xl p-4 space-y-1">
    <div class="text-sm font-semibold text-red-300 mb-2">Query errors — some sections may be empty:</div>
    @foreach($errors as $err)
      <div class="font-mono text-xs text-red-400">{{ $err }}</div>
    @endforeach
  </div>
@endif

{{-- Index by source --}}
@php
  $lotsBySource  = collect($lotsStats)->keyBy('source');
  $rawBySource   = collect($rawStats)->keyBy('source');
  $inspBySource  = collect($inspStats)->keyBy('source');
  $photoBySource = collect($photoStats)->keyBy('source');
  $sources       = $lotsBySource->keys()->toArray();
@endphp

{{-- ── Tab switcher ── --}}
<div class="flex gap-2 mb-6" id="tabs">
  @foreach($sources as $src)
    <button onclick="showTab('{{ $src }}')"
            id="tab-{{ $src }}"
            class="px-4 py-2 rounded-lg text-sm font-medium transition tab-btn
                   {{ $loop->first ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
      {{ strtoupper($src) }}
      @if($lotsBySource->has($src))
        <span class="ml-1 text-xs opacity-70">{{ number_format($lotsBySource[$src]->total) }}</span>
      @endif
    </button>
  @endforeach
</div>

@foreach($sources as $src)
@php
  $lt   = $lotsBySource[$src]  ?? null;
  $raw  = $rawBySource[$src]   ?? null;
  $ins  = $inspBySource[$src]  ?? null;
  $ph   = $photoBySource[$src] ?? null;
  $tot  = $lt?->total ?? 0;
  $itot = $ins?->total_lots ?? 0;
@endphp

<div id="pane-{{ $src }}" class="tab-pane {{ !$loop->first ? 'hidden' : '' }} space-y-6">

  {{-- ── Summary cards ── --}}
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    @php $cards = [
      ['Total lots',        $tot,                              ''],
      ['With inspection',   $ins?->lots_with_insp ?? 0,        $tot ? $pct($ins->lots_with_insp ?? 0, $tot).'%' : '—'],
      ['With photos (DB)',  $ph?->lots_with_photos ?? 0,       $tot ? $pct($ph->lots_with_photos ?? 0, $tot).'%' : '—'],
      ['Avg photos/lot',    ($ph?->avg_photos_per_lot ?? '—'), ''],
    ] @endphp
    @foreach($cards as [$label, $val, $sub])
      <div class="bg-gray-800 rounded-xl p-4">
        <div class="text-xs text-gray-500 mb-1">{{ $label }}</div>
        <div class="text-2xl font-bold text-white">{{ is_numeric($val) ? number_format($val) : $val }}</div>
        @if($sub)<div class="text-xs text-gray-400 mt-0.5">{{ $sub }}</div>@endif
      </div>
    @endforeach
  </div>

  {{-- ── lots table columns ── --}}
  @if($lt)
  <div class="bg-gray-900 rounded-xl overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
      <span class="text-sm font-semibold text-white">lots — основные поля</span>
      <span class="text-xs text-gray-500">по {{ number_format($tot) }} записям</span>
    </div>
    <div class="divide-y divide-gray-800">
      @php $lotsFields = [
        ['trim',             $lt->trim,             'Комплектация / trim'],
        ['vin',              $lt->vin,              'VIN номер'],
        ['plate_number',     $lt->plate_number,     'Гос. номер'],
        ['body_type',        $lt->body_type,        'Тип кузова'],
        ['fuel',             $lt->fuel,             'Тип топлива'],
        ['transmission',     $lt->transmission,     'Коробка передач'],
        ['drive_type',       $lt->drive_type,       'Привод (4WD/FWD/RWD)'],
        ['engine_volume',    $lt->engine_volume,    'Объём двигателя (л)'],
        ['fuel_economy',     $lt->fuel_economy,     'Расход топлива (км/л)'],
        ['mileage',          $lt->mileage,          'Пробег > 0'],
        ['color',            $lt->color,            'Цвет'],
        ['seat_color',       $lt->seat_color,       'Цвет салона'],
        ['has_accident',     $lt->has_accident,     'Факт аварии (bool)'],
        ['insurance_count',  $lt->insurance_count,  'Кол-во страховых случаев'],
        ['owners_count',     $lt->owners_count,     'Кол-во владельцев'],
        ['flood_history',    $lt->flood_history,    'История затопления'],
        ['total_loss_history',$lt->total_loss_history,'Полная гибель'],
        ['registration_date',$lt->registration_date,'Дата первой регистрации'],
        ['price',            $lt->price,            'Цена > 0 (USD)'],
        ['price_krw',        $lt->price_krw,        'Цена в KRW'],
        ['lien_status',      $lt->lien_status,      'Статус залога (заполнен)'],
        ['  → lien ≠ clean', $lt->lien_not_clean,   'Залог — не clean'],
        ['seizure_status',   $lt->seizure_status,   'Статус ареста (заполнен)'],
        ['  → seizure ≠ clean',$lt->seizure_not_clean,'Арест — не clean'],
        ['repair_cost',      $lt->repair_cost,      'Стоимость ремонта (заполнено)'],
        ['  → repair > 0',   $lt->repair_cost_positive, 'Есть стоимость ремонта'],
        ['dealer_name',      $lt->dealer_name,      'Дилер'],
        ['dealer_phone',     $lt->dealer_phone,     'Телефон дилера'],
        ['dealer_company',   $lt->dealer_company,   'Компания дилера'],
        ['location',         $lt->location,         'Местоположение'],
        ['options (has)',     $lt->has_options,      'Список опций заполнен'],
        ['image_url',        $lt->image_url,        'Главное фото'],
        ['warranty_text',    $lt->warranty_text,     'Текст гарантии'],
      ] @endphp

      @foreach($lotsFields as [$field, $cnt, $desc])
        @php
          $isIndent = str_starts_with($field, '  →');
          $p = $pct($cnt, $tot);
        @endphp
        <div class="flex items-center px-5 py-2.5 gap-4 hover:bg-gray-800/40 transition {{ $isIndent ? 'bg-gray-800/20' : '' }}">
          <div class="w-40 shrink-0 {{ $isIndent ? 'pl-4' : '' }}">
            <span class="font-mono text-xs {{ $isIndent ? 'text-gray-500' : 'text-gray-300' }}">{{ $field }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-xs text-gray-500 truncate">{{ $desc }}</div>
            <div class="mt-1 h-1.5 rounded-full bg-gray-700 overflow-hidden">
              <div class="{{ $barColor($p) }} h-full rounded-full" style="width:{{ $p }}%"></div>
            </div>
          </div>
          <div class="w-20 text-right shrink-0">
            <span class="text-sm font-bold {{ $badge($p) }}">{{ $p }}%</span>
            <div class="text-xs text-gray-600">{{ number_format($cnt) }}/{{ number_format($tot) }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
  @endif

  {{-- ── raw_data JSON keys ── --}}
  @if($raw)
  <div class="bg-gray-900 rounded-xl overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
      <span class="text-sm font-semibold text-white">raw_data JSON — дополнительные поля</span>
      <span class="text-xs text-gray-500">из /inspection + /sellingpoint + /diagnosis</span>
    </div>
    <div class="divide-y divide-gray-800">
      @php $rawFields = [
        ['photos',             $raw->photos,             'Список URL фото (→ lot_photos)'],
        ['engine_code',        $raw->engine_code,        'Код двигателя (G6DN, D4CB…) — из inspection'],
        ['warranty_type',      $raw->warranty_type,      'Тип гарантии — из inspection'],
        ['recall',             $raw->recall,             'Наличие отзыва — из inspection'],
        ['car_state',          $raw->car_state,          'Общее состояние (양호/불량) — из inspection'],
        ['mechanical_issues',  $raw->mechanical_issues,  'Механические неисправности — из inspection inners'],
        ['diagnosis_center',   $raw->diagnosis_center,   'Центр Encar-диагностики — из diagnosis'],
        ['inspect_vehicle_id', $raw->inspect_vehicle_id, 'Inner vehicle ID (из пути фото)'],
        ['photo_count',        $raw->photo_count,        'Кол-во фото из API'],
      ] @endphp

      @foreach($rawFields as [$field, $cnt, $desc])
        @php $p = $pct($cnt, $raw->total) @endphp
        <div class="flex items-center px-5 py-2.5 gap-4 hover:bg-gray-800/40 transition">
          <div class="w-44 shrink-0">
            <span class="font-mono text-xs text-blue-300">raw_data.{{ $field }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-xs text-gray-500 truncate">{{ $desc }}</div>
            <div class="mt-1 h-1.5 rounded-full bg-gray-700 overflow-hidden">
              <div class="{{ $barColor($p) }} h-full rounded-full" style="width:{{ $p }}%"></div>
            </div>
          </div>
          <div class="w-20 text-right shrink-0">
            <span class="text-sm font-bold {{ $badge($p) }}">{{ $p }}%</span>
            <div class="text-xs text-gray-600">{{ number_format($cnt) }}/{{ number_format($raw->total) }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
  @endif

  {{-- ── lot_inspections ── --}}
  @if($ins && $ins->lots_with_insp > 0)
  @php $iwith = $ins->lots_with_insp @endphp
  <div class="bg-gray-900 rounded-xl overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
      <span class="text-sm font-semibold text-white">lot_inspections</span>
      <span class="text-xs text-gray-500">
        {{ number_format($iwith) }} / {{ number_format($itot) }} лотов имеют запись
        ({{ $pct($iwith, $itot) }}%)
      </span>
    </div>
    <div class="divide-y divide-gray-800">
      @php $inspFields = [
        ['cert_no',            $ins->cert_no,           'Номер акта осмотра'],
        ['inspection_date',    $ins->inspection_date,   'Дата осмотра'],
        ['valid_from',         $ins->valid_from,        'Действителен с'],
        ['valid_until',        $ins->valid_until,       'Действителен до'],
        ['report_url',         $ins->report_url,        'URL отчёта'],
        ['inspection_mileage', $ins->inspection_mileage,'Пробег на момент осмотра'],
        ['has_accident',       $ins->has_accident,      'Авария (bool заполнен)'],
        ['  → accident=TRUE',  $ins->accident_true,     'Машин с подтверждённой аварией'],
        ['has_outer_damage',   $ins->has_outer_damage,  'Повреждения кузова (bool)'],
        ['  → damage=TRUE',    $ins->outer_damage_true, 'Машин с повреждениями'],
        ['outer_detail',       $ins->outer_detail,      'Текст повреждений по панелям'],
        ['has_flood',          $ins->has_flood,         'Затопление'],
        ['has_tuning',         $ins->has_tuning,        'Тюнинг'],
        ['accident_detail',    $ins->accident_detail,   'Детализация страховых случаев'],
      ] @endphp

      @foreach($inspFields as [$field, $cnt, $desc])
        @php
          $isIndent = str_starts_with($field, '  →');
          $p = $pct($cnt, $itot);
        @endphp
        <div class="flex items-center px-5 py-2.5 gap-4 hover:bg-gray-800/40 transition
                    {{ $isIndent ? 'bg-gray-800/20' : '' }}">
          <div class="w-44 shrink-0 {{ $isIndent ? 'pl-4' : '' }}">
            <span class="font-mono text-xs {{ $isIndent ? 'text-gray-500' : 'text-purple-300' }}">{{ $field }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-xs text-gray-500 truncate">{{ $desc }}</div>
            <div class="mt-1 h-1.5 rounded-full bg-gray-700 overflow-hidden">
              <div class="{{ $barColor($p) }} h-full rounded-full" style="width:{{ $p }}%"></div>
            </div>
          </div>
          <div class="w-20 text-right shrink-0">
            <span class="text-sm font-bold {{ $badge($p) }}">{{ $p }}%</span>
            <div class="text-xs text-gray-600">{{ number_format($cnt) }}/{{ number_format($itot) }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
  @elseif($ins)
  <div class="bg-gray-900 rounded-xl px-5 py-4 text-sm text-gray-500">
    lot_inspections: нет записей для <span class="text-white font-mono">{{ $src }}</span>
  </div>
  @endif

</div>{{-- /pane --}}
@endforeach

<script>
function showTab(src) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
  document.querySelectorAll('.tab-btn').forEach(b => {
    b.classList.remove('bg-blue-600','text-white');
    b.classList.add('bg-gray-800','text-gray-400');
  });
  document.getElementById('pane-'+src).classList.remove('hidden');
  const btn = document.getElementById('tab-'+src);
  btn.classList.add('bg-blue-600','text-white');
  btn.classList.remove('bg-gray-800','text-gray-400');
}
</script>
@endsection
