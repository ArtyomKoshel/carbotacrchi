@php
    use App\Filament\Pages\Logs;
    $current = $this->current();
    $lines = $this->lines();
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4" @if ($follow) wire:poll.5s @endif>
        <div class="review-toolbar">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="file" aria-label="Файл">
                    @forelse ($this->files() as $group => $files)
                        <optgroup label="{{ $group }}">
                            @foreach ($files as $f)
                                <option value="{{ $f['key'] }}">{{ $f['label'] }} · {{ isset($f['job_id']) ? $f['lines'] . ' стр.' : \Illuminate\Support\Number::fileSize($f['size'], 1) }}</option>
                            @endforeach
                        </optgroup>
                    @empty
                        <option value="">Нет файлов</option>
                    @endforelse
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="level" aria-label="Уровень">
                    <option value="">Все уровни</option>
                    @foreach (Logs::LEVELS as $l)
                        <option value="{{ $l }}">{{ $l }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.500ms="q" placeholder="Текст в строке" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="limit" aria-label="Сколько строк">
                    @foreach (Logs::LIMITS as $n)
                        <option value="{{ $n }}">последние {{ $n }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <label class="settings-check">
                <x-filament::input.checkbox wire:model.live="follow" />
                <span>Обновлять каждые 5 с</span>
            </label>
        </div>

        @if (!$current)
            <x-filament::section>
                <p class="lot-empty">Файлов логов нет. Лог парсера задаётся PARSER_LOG_FILE.</p>
            </x-filament::section>
        @else
            <p class="panel-intro">
                {{ $current['path'] }} · изменён {{ \Carbon\Carbon::createFromTimestamp($current['mtime'])->diffForHumans() }} ·
                показано {{ count($lines) }}, новые сверху
            </p>
            @if (empty($lines))
                <x-filament::section><p class="lot-empty">Нет строк под фильтр.</p></x-filament::section>
            @else
                <pre class="log-lines log-lines-tall">@foreach ($lines as $line)<span @class(['log-error' => str_contains($line, 'ERROR') || str_contains($line, 'CRITICAL'), 'log-warn' => str_contains($line, 'WARNING')])>{{ $line }}</span>
@endforeach</pre>
            @endif
        @endif
    </div>
</x-filament-panels::page>
