@php
    use App\Filament\Resources\Lots\LotFormat;
    $rows = $getRecord()->subscriptions()->latest()->limit(20)->get();
    $describe = fn (array $q) => collect([
        $q['make'] ?? null,
        $q['model'] ?? null,
        isset($q['yearFrom']) || isset($q['yearTo']) ? trim(($q['yearFrom'] ?? '') . '–' . ($q['yearTo'] ?? ''), '–') : null,
        isset($q['priceMax']) ? 'до ' . LotFormat::won($q['priceMax']) : null,
        isset($q['mileageMax']) ? 'до ' . LotFormat::km($q['mileageMax']) : null,
    ])->filter()->implode(' · ');
@endphp
@if ($rows->isEmpty())
    <p class="lot-empty">Подписок нет.</p>
@else
    <table class="kv-table">
        <thead><tr><th>Что ищет</th><th>Новых</th><th>Проверено</th><th>Статус</th></tr></thead>
        <tbody>
            @foreach ($rows as $s)
                <tr>
                    <td>{{ $describe((array) $s->query) ?: '—' }}</td>
                    <td>{{ LotFormat::number((int) $s->new_lots_count) }}</td>
                    <td>{{ $s->last_checked_at?->diffForHumans() ?? '—' }}</td>
                    <td>
                        <x-filament::badge :color="$s->active ? 'success' : 'gray'">{{ $s->active ? 'активна' : 'выключена' }}</x-filament::badge>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
