@php
    $o = $this->overview();
    $checks = ['db' => 'База данных', 'proxy' => 'Прокси FloppyData', 'ai' => 'DeepSeek'];
@endphp

<x-filament-panels::page>
    <p class="panel-intro">
        Уведомления рассылает <code>notifications:watch</code> каждые 5 минут: задачи парсинга, падение базы, низкие балансы,
        долгое отсутствие успешного прогона. Каждое событие уходит один раз; инциденты повторяются по порогу «Повторять».
    </p>

    {{ $this->table }}

    {{ $this->thresholdsForm }}

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Последняя проверка" compact>
            <dl class="balances">
                @foreach ($checks as $key => $label)
                    @php $s = $o['status'][$key] ?? null; @endphp
                    <div class="balance">
                        <dt class="health-label">{{ $label }}</dt>
                        <dd>
                            @if (!$s)
                                <span class="health-value-sm">—</span><span class="health-hint">не проверялось</span>
                            @elseif (isset($s['skipped']))
                                <span class="health-value-sm">—</span><span class="health-hint">не настроено</span>
                            @elseif (!empty($s['ok']))
                                <x-filament::badge color="success">в порядке</x-filament::badge>
                                <span class="health-hint">
                                    @if (isset($s['gb'])) {{ number_format((float) $s['gb'], 2, ',', ' ') }} ГБ · @endif
                                    @if (isset($s['amount'])) {{ number_format((float) $s['amount'], 2, ',', ' ') }} {{ $s['currency'] ?? '' }} · @endif
                                    {{ \Carbon\Carbon::parse($s['at'])->diffForHumans() }}
                                </span>
                            @else
                                <x-filament::badge color="danger">ошибка</x-filament::badge>
                                <span class="health-hint">{{ $s['error'] ?? '' }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($o['dbDown'])
                <p class="health-hint">База была недоступна с {{ \Carbon\Carbon::createFromTimestamp($o['dbDown']['since'] ?? time())->format('d.m.Y H:i') }}.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Открытые инциденты" compact>
            @forelse ($o['events'] as $event)
                <div class="incident">
                    <div class="incident-head">
                        <x-filament::badge color="warning">{{ $o['types'][$event->type] ?? $event->type }}</x-filament::badge>
                        <time class="incident-time">с {{ $event->opened_at?->diffForHumans() }}</time>
                    </div>
                    <code class="incident-key">{{ $event->event_key }}</code>
                </div>
            @empty
                <p class="lot-empty">Нет.</p>
            @endforelse
        </x-filament::section>
    </div>

    <x-filament::section heading="Последние отправки" compact collapsible>
        @if ($o['log']->isEmpty())
            <p class="lot-empty">Ещё ничего не отправлялось.</p>
        @else
            <table class="kv-table">
                <thead><tr><th>Когда</th><th>Тип</th><th>Кому</th><th>Результат</th></tr></thead>
                <tbody>
                    @foreach ($o['log'] as $row)
                        <tr>
                            <td>{{ $row->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $o['types'][$row->type] ?? $row->type }}</td>
                            <td><code>{{ $row->chat_id }}</code></td>
                            <td>
                                @if ($row->ok)
                                    <x-filament::badge color="success">доставлено</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger">ошибка</x-filament::badge>
                                    <span class="health-hint">{{ $row->error }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
