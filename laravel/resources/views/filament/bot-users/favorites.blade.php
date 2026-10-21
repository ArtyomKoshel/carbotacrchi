@php
    use App\Filament\Resources\Lots\LotFormat;
    use App\Filament\Resources\Lots\LotResource;
    $rows = $getRecord()->favorites()->latest()->limit(20)->get();
@endphp
@if ($rows->isEmpty())
    <p class="lot-empty">В избранном пусто.</p>
@else
    <ul class="review-examples">
        @foreach ($rows as $f)
            @php $d = (array) $f->lot_data; @endphp
            <li>
                <a href="{{ LotResource::getUrl('view', ['record' => $f->lot_id]) }}" target="_blank">
                    {{ $d['title'] ?? trim(($d['make'] ?? '') . ' ' . ($d['model'] ?? '')) ?: $f->lot_id }}
                </a>
                <span>{{ collect([$d['year'] ?? null, isset($d['price']) ? LotFormat::won($d['price']) : null, $f->created_at?->format('d.m.Y')])->filter()->implode(' · ') }}</span>
            </li>
        @endforeach
    </ul>
@endif
