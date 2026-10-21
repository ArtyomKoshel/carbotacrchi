@php
    use App\Filament\Resources\Lots\LotFormat;
    use App\Support\AdminUiLabels;

    $changes = $getRecord()->lotChanges()
        ->orderByDesc('recorded_at')->orderByDesc('id')
        ->limit(200)
        ->get();

    $value = fn (string $field, mixed $v) => LotFormat::changeValue($field, $v);
@endphp

<div class="lot-history">
    @forelse ($changes as $change)
        <div class="lot-history-item">
            <div class="lot-history-head">
                <span class="lot-history-event">{{ AdminUiLabels::event($change->event) }}</span>
                <time class="lot-history-time" datetime="{{ $change->recorded_at?->toIso8601String() }}"
                      title="{{ $change->recorded_at?->diffForHumans() }}">
                    {{ $change->recorded_at?->format('d.m.Y H:i') }}
                </time>
            </div>
            <dl class="lot-history-fields">
                @foreach ((array) $change->changes as $field => $diff)
                    <dt>{{ AdminUiLabels::field($field) }}</dt>
                    <dd>
                        @if (is_array($diff) && (array_key_exists('old', $diff) || array_key_exists('new', $diff)))
                            <span class="lot-history-old">{{ $value($field, $diff['old'] ?? null) }}</span>
                            <span class="lot-history-arrow" aria-hidden="true">→</span>
                            <span class="lot-history-new">{{ $value($field, $diff['new'] ?? null) }}</span>
                        @else
                            {{ $value($field, $diff) }}
                        @endif
                    </dd>
                @endforeach
            </dl>
        </div>
    @empty
        <p class="lot-empty">Изменений пока нет. Сюда попадают цена, статус и поля истории, когда парсер видит, что они поменялись.</p>
    @endforelse

    @if ($changes->count() === 200)
        <p class="lot-empty">Показаны последние 200 изменений.</p>
    @endif
</div>
