@php $rows = $getRecord()->searches()->latest()->limit(15)->get(); @endphp
@if ($rows->isEmpty())
    <p class="lot-empty">Поисков не было.</p>
@else
    <table class="kv-table">
        <thead><tr><th>Когда</th><th>Что искал</th><th>Нашлось</th></tr></thead>
        <tbody>
            @foreach ($rows as $s)
                <tr>
                    <td>{{ $s->created_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ collect([$s->make, $s->model, $s->year_from || $s->year_to ? trim(($s->year_from ?: '') . '–' . ($s->year_to ?: ''), '–') : null, $s->price_max ? 'до ' . \App\Filament\Resources\Lots\LotFormat::won($s->price_max) : null])->filter()->implode(' · ') ?: '—' }}@if ($s->relaxed) <span class="health-hint">с допуском</span>@endif</td>
                    <td>{{ \App\Filament\Resources\Lots\LotFormat::number((int) $s->results_cnt) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
