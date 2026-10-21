@php use App\Filament\Resources\Lots\LotFormat; @endphp
<x-filament-widgets::widget>
    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <x-filament::section heading="Изменений по дням, 14 дней" compact>
            <div class="days-chart" role="img" aria-label="Изменения лотов по дням">
                @foreach ($days as $day => $byEvent)
                    @php $total = array_sum($byEvent); @endphp
                    <div class="days-col" title="{{ \Carbon\Carbon::parse($day)->format('d.m') }}: {{ collect($byEvent)->map(fn ($n, $e) => ($events[$e] ?? $e) . ' ' . LotFormat::number($n))->implode(', ') ?: 'нет' }}">
                        <div class="days-bar" style="height: {{ round($total / $max * 100) }}%"></div>
                        <span class="days-label">{{ \Carbon\Carbon::parse($day)->format('d') }}</span>
                    </div>
                @endforeach
            </div>
            <p class="health-hint">Наведите на столбец, чтобы увидеть разбивку по событиям.</p>
        </x-filament::section>
        <x-filament::section heading="Чаще всего менялись, 7 дней" compact>
            @forelse ($top as $row)
                <div class="incident-head">
                    <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\Lots\LotResource::getUrl('view', ['record' => $row->lot_id]) }}">{{ $row->lot_id }}</a>
                    <span class="incident-time">{{ LotFormat::number((int) $row->n) }}</span>
                </div>
            @empty
                <p class="lot-empty">Нет изменений.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
