@php
    use App\Filament\Resources\Lots\LotFormat;
    use App\Services\ParseRuns;
    use App\Support\JobFormat;
@endphp

<div class="flex flex-col gap-6">
    {{-- The run in progress: refreshed every 5 s while there is one --}}
    <div @if ($current) wire:poll.5s @endif>
        @if ($current)
            @php
                $p = (array) ($current->progress ?? []);
                $s = ParseRuns::stats($current);
                $pct = $s['pct'] ?? (isset($p['pct']) ? (float) $p['pct'] : null);
                $perMin = $s['elapsed'] > 0 ? $s['done'] / ($s['elapsed'] / 60) : 0;
                $eta = ($perMin > 0 && $s['goal'] > $s['done']) ? ($s['goal'] - $s['done']) / $perMin * 60 : null;
                $phase = $p['phase'] ?? null;
                // che168 only searches: no history, no delisting (its runs are never full)
                $phases = ($p['checkpoint']['engine'] ?? null) === 'che168' ? ['search'] : ['search', 'enrich', 'delist'];
            @endphp
            <x-filament::section>
                <x-slot name="heading">
                    Прогон #{{ $current->id }} · {{ JobFormat::kind($current) }}
                </x-slot>
                <x-slot name="description">
                    Запустил {{ JobFormat::triggeredBy($current->triggered_by) }}, {{ $current->created_at?->diffForHumans() }}
                </x-slot>
                <x-slot name="afterHeader">
                    <x-filament::badge :color="JobFormat::statusColor($current->status)">
                        {{ JobFormat::status($current->status) }}
                    </x-filament::badge>
                    {{ ($this->cancelAction)(['job' => $current->id]) }}
                </x-slot>

                <div class="run-progress">
                    <div class="run-progress-head">
                        <span>{{ JobFormat::phase($phase) }}</span>
                        <span class="tabular-nums">{{ $pct !== null ? number_format($pct, 1, ',', '') . ' %' : '—' }}</span>
                    </div>
                    <div class="run-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) ($pct ?? 0) }}">
                        <div class="run-progress-bar" style="width: {{ min(100, max(0, (float) ($pct ?? 0))) }}%"></div>
                    </div>
                    <ol class="run-phases">
                        @foreach ($phases as $step)
                            <li @class(['is-current' => $phase === $step])>{{ JobFormat::phase($step) }}</li>
                        @endforeach
                    </ol>
                </div>

                <dl class="run-stats">
                    <div><dt>Лоты</dt><dd>{{ LotFormat::number($s['done']) }}{{ $s['goal'] > 0 ? ' / ' . LotFormat::number($s['goal']) : '' }}@if ($s['goal'] !== $s['total'] && $s['total'] > 0)<small class="block text-gray-500">на площадке {{ LotFormat::number($s['total']) }}</small>@endif</dd></div>
                    <div><dt>Новые</dt><dd>{{ LotFormat::number($s['new']) }}</dd></div>
                    <div><dt>Обновлено</dt><dd>{{ LotFormat::number($s['updated']) }}</dd></div>
                    <div><dt>Ошибки</dt><dd>{{ LotFormat::number($s['errors']) }}</dd></div>
                    <div><dt>Идёт</dt><dd>{{ JobFormat::duration($s['elapsed']) }}</dd></div>
                    <div><dt>Осталось</dt><dd>{{ $eta ? '~' . JobFormat::duration($eta) : '—' }}</dd></div>
                    <div><dt>Прокси</dt><dd>{{ $s['proxy_bytes'] > 0 ? ParseRuns::gb($s['proxy_bytes']) : '—' }}</dd></div>
                </dl>

                @if (!empty($p['message']))
                    <p class="run-message">{{ $p['message'] }}</p>
                @endif
            </x-filament::section>
        @endif
    </div>

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
