@php
    use App\Filament\Pages\SearchSettings;
    $groups = $this->settings();
@endphp

<x-filament-panels::page>
    <p class="panel-intro">
        Какие поля участвуют в поиске бота и сайта, насколько расширять одно названное значение и что показывать в карточке лота.
        Допуск превращает «2020 года» или «2.0 л» в диапазон; диапазоны, которые назвал клиент («2019–2022»), не расширяются.
        Каждая строка сохраняется сразу.
    </p>

    @foreach (['key' => 'Основные поля', 'other' => 'Все поля'] as $group => $heading)
        <x-filament::section :heading="$heading" :collapsible="$group === 'other'" :collapsed="$group === 'other'" compact>
            <table class="settings-table">
                <thead>
                    <tr>
                        <th scope="col">Поле</th>
                        <th scope="col">В поиске</th>
                        <th scope="col">Допуск</th>
                        <th scope="col">В карточке</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups[$group] as $s)
                        @php $row = $rows[$s->id] ?? null; @endphp
                        @continue(!$row)
                        <tr wire:key="setting-{{ $s->id }}">
                            <th scope="row">
                                {{ $s->field_label ?: $s->field_name }}
                                <span class="matrix-code">{{ $s->field_name }}</span>
                                @if ($savedId === $s->id)
                                    <span class="settings-saved" wire:transition>сохранено</span>
                                @endif
                            </th>
                            <td>
                                <label class="settings-check">
                                    <x-filament::input.checkbox wire:model.live="rows.{{ $s->id }}.enabled" />
                                    <span>{{ $row['enabled'] ? 'да' : 'нет' }}</span>
                                </label>
                            </td>
                            <td>
                                @if (SearchSettings::supportsTolerance($s))
                                    <div class="settings-tolerance">
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="rows.{{ $s->id }}.tolerance_type" :disabled="!$row['enabled']" aria-label="Тип допуска">
                                                <option value="none">точно</option>
                                                <option value="absolute">± {{ SearchSettings::UNITS[$s->field_name] ?? 'единиц' }}</option>
                                                <option value="percentage">± %</option>
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @if ($row['tolerance_type'] !== 'none')
                                            <x-filament::input.wrapper class="settings-num">
                                                <x-filament::input type="number" min="0" step="any"
                                                    wire:model.live.debounce.600ms="rows.{{ $s->id }}.tolerance_value" aria-label="Величина допуска" />
                                            </x-filament::input.wrapper>
                                        @endif
                                        <span class="settings-human">{{ SearchSettings::toleranceText($s, $row) }}</span>
                                    </div>
                                @else
                                    <span class="matrix-none">—</span>
                                @endif
                            </td>
                            <td>
                                <label class="settings-check">
                                    <x-filament::input.checkbox wire:model.live="rows.{{ $s->id }}.display_in_card" />
                                    <span>{{ $row['display_in_card'] ? 'да' : 'нет' }}</span>
                                </label>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endforeach

    <x-filament::section heading="Проверить разбор запроса" description="Как бот поймёт текст клиента: что ищет точно и что с допуском. Запрос уходит в AI, как в боте.">
        <form wire:submit="runTest" class="settings-test">
            <x-filament::input.wrapper class="settings-test-input">
                <x-filament::input type="text" wire:model="testText" placeholder="Например: Sonata 2020 года до 20 млн, пробег до 80 тысяч" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" color="gray" icon="heroicon-o-play" wire:loading.attr="disabled" wire:target="runTest">
                Разобрать
            </x-filament::button>
        </form>

        @if ($testResult)
            @if (isset($testResult['error']))
                <p class="lot-empty">{{ $testResult['error'] }}</p>
            @else
                <p class="settings-test-desc">
                    {{ $testResult['description'] }}
                    <span class="health-hint">Разбор: {{ $testResult['mode'] }}. {{ $testResult['note'] }}</span>
                </p>
                <table class="kv-table">
                    <thead><tr><th>Параметр</th><th>Точно</th><th>С допуском</th></tr></thead>
                    <tbody>
                        @foreach ($testResult['rows'] as $r)
                            <tr>
                                <td><code>{{ $r['key'] }}</code></td>
                                <td>{{ $r['exact'] }}</td>
                                <td @class(['settings-diff' => $r['exact'] !== $r['wide']])>{{ $r['wide'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
