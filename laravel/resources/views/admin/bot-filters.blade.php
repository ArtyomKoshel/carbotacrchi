@extends('admin.layout')
@section('title', 'Bot Filters')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  <p class="font-semibold mb-1">Validation errors:</p>
  <ul class="list-disc list-inside">
    @foreach($errors->all() as $error)
      <li>{{ $error }}</li>
    @endforeach
  </ul>
</div>
@endif

<p class="text-sm text-gray-500 mb-6">
  Управление полями, которые бот может парсить из текста, и их допусками при поиске.
  Настройки кэшируются на 60 секунд.
</p>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
    <div>
      <div class="text-sm font-semibold text-white">Bot Filters Settings</div>
      <div class="text-xs text-gray-500 mt-1">{{ $settings->count() }} field(s) available</div>
    </div>
    <div class="flex items-center gap-2">
      <form method="POST" action="{{ route('admin.bot-filters.reset') }}"
            onsubmit="return confirm('Сбросить настройки к дефолтным значениям?')">
        @csrf
        <button type="submit"
                class="px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm transition">
          Reset defaults
        </button>
      </form>
      <button type="submit" form="bot-filters-form"
              class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
        Save settings
      </button>
    </div>
  </div>

  <form id="bot-filters-form" method="POST" action="{{ route('admin.bot-filters.update') }}">
    @csrf

    @forelse($categories as $category => $rows)
      <div class="px-5 py-3 border-b border-gray-800/70 bg-gray-900/70">
        <div class="text-xs uppercase tracking-wider text-gray-500 font-semibold">{{ $category ?: 'other' }}</div>
      </div>

      <div class="divide-y divide-gray-800/60">
        @foreach($rows as $setting)
          @php
            $supportsTolerance = in_array($setting->dtype, ['int', 'float', 'date'], true);
            $tolType = $setting->tolerance_type ?: 'none';
            $tolValue = $setting->tolerance_value;
            $tolDisplay = $tolType === 'percentage' && $tolValue !== null ? $tolValue * 100 : $tolValue;
          @endphp

          <div class="px-5 py-3 grid grid-cols-12 gap-3 items-center text-sm">
            <div class="col-span-3 min-w-0">
              <div class="text-white font-medium truncate">{{ $setting->field_name }}</div>
              <div class="text-[11px] text-gray-500 truncate">{{ $setting->description ?: $setting->field_label }}</div>
            </div>

            <div class="col-span-1 text-xs">
              <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-400">{{ $setting->dtype }}</span>
            </div>

            <div class="col-span-2">
              <label class="inline-flex items-center gap-2 text-xs text-gray-300">
                <input type="checkbox" name="fields[{{ $setting->id }}][enabled]" value="1"
                       {{ $setting->enabled ? 'checked' : '' }}
                       class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-600">
                Enabled
              </label>
            </div>

            <div class="col-span-4 flex items-center gap-2">
              @if($supportsTolerance)
                <select name="fields[{{ $setting->id }}][tolerance_type]"
                        class="bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white">
                  <option value="none" {{ $tolType === 'none' ? 'selected' : '' }}>none</option>
                  <option value="absolute" {{ $tolType === 'absolute' ? 'selected' : '' }}>abs</option>
                  <option value="percentage" {{ $tolType === 'percentage' ? 'selected' : '' }}>pct</option>
                </select>
                <input type="number" step="0.0001" min="0"
                       name="fields[{{ $setting->id }}][tolerance_value]"
                       value="{{ $tolDisplay !== null ? $tolDisplay : '' }}"
                       class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white"
                       placeholder="value">
              @else
                <input type="hidden" name="fields[{{ $setting->id }}][tolerance_type]" value="none">
                <input type="hidden" name="fields[{{ $setting->id }}][tolerance_value]" value="">
                <span class="text-xs text-gray-600">—</span>
              @endif
            </div>

            <div class="col-span-2">
              <label class="inline-flex items-center gap-2 text-xs text-gray-300">
                <input type="checkbox" name="fields[{{ $setting->id }}][display_in_card]" value="1"
                       {{ $setting->display_in_card ? 'checked' : '' }}
                       class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-600">
                Card
              </label>
            </div>
          </div>
        @endforeach
      </div>
    @empty
      <div class="px-5 py-10 text-center text-gray-500 text-sm">No settings found.</div>
    @endforelse

    <div class="px-5 py-4 border-t border-gray-800 flex justify-end">
      <button type="submit"
              class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
        Save settings
      </button>
    </div>
  </form>
</div>

@endsection
