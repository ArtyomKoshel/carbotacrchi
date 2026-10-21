@php
    use App\Filament\Resources\Lots\LotFormat;
    use App\Support\JobFormat;
@endphp
<table class="kv-table">
    <thead><tr><th>Фаза</th><th>На входе</th><th>На выходе</th><th>Ошибки</th><th>Время</th></tr></thead>
    <tbody>
        @foreach ($phases as $phase)
            <tr>
                <td>{{ JobFormat::phase($phase['name'] ?? null) }}</td>
                <td>{{ LotFormat::number((int) ($phase['lots_in'] ?? 0)) }}</td>
                <td>{{ LotFormat::number((int) ($phase['lots_out'] ?? 0)) }}</td>
                <td>{{ LotFormat::number((int) ($phase['errors'] ?? 0)) }}</td>
                <td>{{ JobFormat::duration((float) ($phase['elapsed_s'] ?? 0)) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
