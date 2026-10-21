@php
    use App\Filament\Pages\FieldCoverage;
    use App\Filament\Resources\Lots\LotFormat;
    use App\Support\Platforms;

    $m = $this->matrix();
@endphp

<x-filament-panels::page>
    <p class="panel-intro">
        Доля активных лотов, у которых поле заполнено.
        @if ($m['computed']) Посчитано {{ $m['computed'] }}. @endif
        Меньше {{ FieldCoverage::LOW_PCT }} % отмечено как проблема.
    </p>

    @if (empty($m['groups']))
        <x-filament::section>
            <p class="lot-empty">Покрытие ещё не считалось. Нажмите «Пересчитать».</p>
        </x-filament::section>
    @else
        <div class="matrix-wrap">
            <table class="matrix">
                <thead>
                    <tr>
                        <th scope="col">Поле</th>
                        @foreach ($m['sources'] as $source)
                            <th scope="col" class="matrix-num">{{ Platforms::label($source) }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($m['groups'] as $group => $fields)
                    <tbody>
                        <tr class="matrix-group"><th colspan="{{ count($m['sources']) + 1 }}" scope="rowgroup">{{ $group }}</th></tr>
                        @foreach ($fields as $field => $bySource)
                            <tr>
                                <th scope="row">
                                    {{ FieldCoverage::fieldLabel($field) }}
                                    <span class="matrix-code">{{ $field }}</span>
                                </th>
                                @foreach ($m['sources'] as $source)
                                    @php $s = $bySource[$source] ?? null; @endphp
                                    <td class="matrix-num">
                                        @if ($s)
                                            <span @class(['matrix-pct', 'is-low' => $s->coverage_pct < FieldCoverage::LOW_PCT])
                                                  title="{{ LotFormat::number($s->filled_lots) }} из {{ LotFormat::number($s->total_lots) }}">
                                                {{ number_format($s->coverage_pct, 1, ',', '') }} %
                                            </span>
                                            <span class="matrix-bar"><span style="width: {{ min(100, $s->coverage_pct) }}%"></span></span>
                                        @else
                                            <span class="matrix-none">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</x-filament-panels::page>
