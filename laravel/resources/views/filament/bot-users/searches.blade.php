@php
    use App\Filament\Resources\BotUsers\SearchDescriber;
    $rows = $getRecord()->searches()->latest()->limit(15)->get();
@endphp
@if ($rows->isEmpty())
    <p class="lot-empty">Поисков не было.</p>
@else
    <table class="kv-table">
        <thead><tr><th>Когда</th><th>Что искал</th><th>Нашлось</th></tr></thead>
        <tbody>
            @foreach ($rows as $s)
                {{-- Older rows have no query JSON: fall back to the flat columns. --}}
                @php $q = $s->query ?: array_filter(['make' => $s->make, 'model' => $s->model, 'yearFrom' => $s->year_from, 'yearTo' => $s->year_to, 'priceMax' => $s->price_max]); @endphp
                <tr>
                    <td>{{ $s->created_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ SearchDescriber::describe((array) $q) ?: 'Все лоты, без фильтров' }}@if ($s->relaxed) <span class="health-hint">с допуском</span>@endif</td>
                    <td>{{ \App\Filament\Resources\Lots\LotFormat::number((int) $s->results_cnt) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
