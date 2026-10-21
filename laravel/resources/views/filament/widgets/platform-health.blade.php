@php
    use App\Filament\Pages\Platform;
    use App\Filament\Resources\Lots\LotFormat;
    use App\Livewire\Platform\Runs;
    use App\Services\ParseRuns;
    use App\Support\JobFormat;
    use App\Support\Platforms;
@endphp

<x-filament-widgets::widget>
    <div class="health-grid">
        @foreach ($platforms as $p)
            @php
                $last = $p['last'];
                $lastStats = $last ? ParseRuns::stats($last) : null;
                $series = array_column($p['series'], 'active');
                $min = $series ? min($series) : 0;
                $max = $series ? max($series) : 0;
                $n = count($series);
                $points = collect($series)->map(fn ($v, $i) => sprintf(
                    '%.1f,%.1f',
                    $n > 1 ? $i / ($n - 1) * 100 : 50,
                    $max > $min ? 28 - ($v - $min) / ($max - $min) * 24 : 16,
                ))->implode(' ');
                $hoursSince = $last ? $last->updated_at?->diffInHours(now()) : null;
            @endphp
            <x-filament::section>
                <x-slot name="heading">
                    <a href="{{ Platform::getUrl(['source' => $p['source']]) }}" class="health-title">{{ Platforms::label($p['source']) }}</a>
                </x-slot>
                <x-slot name="afterHeader">
                    @if ($p['current'])
                        <x-filament::badge :color="JobFormat::statusColor($p['current']->status)">
                            Прогон #{{ $p['current']->id }}: {{ JobFormat::status($p['current']->status) }}
                            @if (($pct = ParseRuns::stats($p['current'])['pct']) !== null)
                                · {{ number_format($pct, 0) }} %
                            @endif
                        </x-filament::badge>
                    @endif
                </x-slot>

                <div class="health-body">
                    <div class="health-metric health-metric-main">
                        <span class="health-label">Активных лотов</span>
                        <span class="health-value">{{ LotFormat::number($p['active']) }}</span>
                        @if ($n > 1)
                            <svg class="sparkline" viewBox="0 0 100 32" preserveAspectRatio="none" role="img"
                                 aria-label="Активные лоты за 14 дней: от {{ LotFormat::number($min) }} до {{ LotFormat::number($max) }}">
                                <polyline points="{{ $points }}" fill="none" vector-effect="non-scaling-stroke" />
                            </svg>
                            <span class="health-hint">14 дней, по прогонам: {{ LotFormat::number($min) }}–{{ LotFormat::number($max) }}</span>
                        @endif
                    </div>

                    <dl class="health-metrics">
                        <div class="health-metric">
                            <dt class="health-label">Последний успешный прогон</dt>
                            <dd>
                                @if ($last)
                                    <span @class(['health-value-sm', 'is-warn' => $hoursSince !== null && $hoursSince > 26])>{{ $last->updated_at?->diffForHumans() }}</span>
                                    <span class="health-hint">#{{ $last->id }} · {{ JobFormat::duration($lastStats['elapsed']) }} · охват {{ $last->result['coverage_pct'] ?? '—' }} %</span>
                                @else
                                    <span class="health-value-sm is-warn">не было</span>
                                    <span class="health-hint">Пропавшие лоты не снимаются, пока нет успешного полного прогона.</span>
                                @endif
                            </dd>
                        </div>
                        <div class="health-metric">
                            <dt class="health-label">За 24 часа</dt>
                            <dd>
                                <span class="health-value-sm">+{{ LotFormat::number($p['new24']) }} новых</span>
                                <span class="health-hint">снято {{ LotFormat::number($p['delisted24']) }}</span>
                            </dd>
                        </div>
                        <div class="health-metric">
                            <dt class="health-label">Заполненность полей</dt>
                            <dd>
                                @if ($p['coverage'] !== null)
                                    <span class="health-value-sm">{{ number_format($p['coverage'], 1, ',', '') }} %</span>
                                    <span class="health-hint">в среднем по полям, {{ \Carbon\Carbon::parse($p['coverage_at'])->diffForHumans() }}</span>
                                @else
                                    <span class="health-value-sm">—</span>
                                    <span class="health-hint">не считалась (Площадка → Поля)</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
