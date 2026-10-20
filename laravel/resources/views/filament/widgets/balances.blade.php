@php
    $rows = [
        ['Прокси FloppyData', $proxy, fn ($r) => number_format((float) $r['gb'], 2, ',', ' ') . ' ГБ', fn ($r) => $minGb !== null && (float) $r['gb'] < $minGb,
            fn ($r) => !empty($r['expires_at']) ? 'подписка до ' . \Carbon\Carbon::parse($r['expires_at'])->format('d.m.Y') : null],
        ['DeepSeek', $ai, fn ($r) => number_format((float) $r['amount'], 2, ',', ' ') . ' ' . ($r['currency'] ?? 'USD'), fn ($r) => $minUsd !== null && (float) $r['amount'] < $minUsd,
            fn ($r) => null],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Балансы">
        <dl class="balances">
            @foreach ($rows as [$label, $row, $value, $low, $extra])
                <div class="balance">
                    <dt class="health-label">{{ $label }}</dt>
                    <dd>
                        @if (isset($row['skipped']))
                            <span class="health-value-sm">—</span>
                            <span class="health-hint">не настроено</span>
                        @elseif (empty($row['ok']))
                            <x-filament::badge color="danger">не удалось проверить</x-filament::badge>
                            <span class="health-hint">{{ $row['error'] ?? '' }}</span>
                        @else
                            <span @class(['health-value-sm', 'is-warn' => $low($row)])>{{ $value($row) }}</span>
                            @if ($low($row))
                                <x-filament::badge color="warning">ниже порога</x-filament::badge>
                            @endif
                            <span class="health-hint">
                                {{ collect([$extra($row), 'проверено ' . \Carbon\Carbon::parse($row['at'])->diffForHumans()])->filter()->implode(' · ') }}
                            </span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
