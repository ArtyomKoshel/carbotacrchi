@php
    $record  = $getRecord();
    $raw     = (array) ($record->raw_data ?? []);
    $preNorm = $raw['pre_norm'] ?? null;
    unset($raw['pre_norm']);

    $paid = $record->getAttribute('paid_options');
    $paid = is_string($paid) ? json_decode($paid, true) : $paid;

    $json = fn (mixed $v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // [key, label, data, open by default]
    $blocks = array_filter([
        ['raw_data', 'данные площадки, которые не попали в колонки', $raw ?: null, true],
        ['raw_data.pre_norm', 'состояние до первой нормализации', $preNorm, false],
        ['options', 'коды опций', $record->options, false],
        ['paid_options', 'платные опции', $paid, false],
        ['lot_inspections.details', 'подробности осмотра', $record->inspection?->details, false],
    ], fn (array $block) => $block[2] !== null && $block[2] !== []);
@endphp

<div class="lot-raw">
    @forelse ($blocks as [$key, $label, $data, $open])
        <details class="lot-raw-block" @if ($open) open @endif>
            <summary><code>{{ $key }}</code> <span>{{ $label }}</span></summary>
            <pre>{{ $json($data) }}</pre>
        </details>
    @empty
        <p class="lot-empty">Сырых данных нет.</p>
    @endforelse
</div>
