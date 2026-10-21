@php use App\Filament\Resources\Lots\LotFormat; @endphp
<table class="kv-table">
    <tbody>
        @foreach (collect($counts)->sortDesc() as $key => $count)
            <tr><td><code>{{ $key }}</code></td><td>{{ LotFormat::number((int) $count) }}</td></tr>
        @endforeach
    </tbody>
</table>
