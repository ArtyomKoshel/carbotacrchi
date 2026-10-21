@php
    use App\Filament\Resources\Lots\LotFormat;
    use App\Models\Translation;
    use App\Services\TranslationCatalog;
    use Illuminate\Contracts\Pagination\LengthAwarePaginator;

    $items = $this->items();
    $list = $items instanceof LengthAwarePaginator ? collect($items->items()) : $items;
    $current = $this->current();
    $context = $this->context();
    $counts = $this->counts();
    $category = $this->currentCategory();
@endphp

<x-filament-panels::page>
    <div
        class="review"
        x-data
        x-on:keydown.window="
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.target.isContentEditable) return;
            if ($event.ctrlKey || $event.metaKey || $event.altKey) return;
            if (document.querySelector('.fi-modal-window')?.offsetParent) return;
            switch ($event.key.toLowerCase()) {
                case 'j': $event.preventDefault(); $wire.next(); break;
                case 'k': $event.preventDefault(); $wire.previous(); break;
                case 'a': $event.preventDefault(); $wire.acceptCurrent(); break;
                case 'e': $event.preventDefault(); $wire.mountAction('edit'); break;
                case 'd': $event.preventDefault(); $wire.mountAction('reject'); break;
            }
        "
    >
        <x-filament::tabs label="Очередь">
            @foreach ($this::STATUSES as $key => $label)
                <x-filament::tabs.item :active="$status === $key" wire:click="$set('status', '{{ $key }}')"
                    :badge="isset($counts[$key]) ? LotFormat::number($counts[$key]) : null" badge-color="gray">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div class="review-toolbar">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="category" aria-label="Что переводится">
                    @if ($status !== 'missing')
                        <option value="">Все поля</option>
                    @endif
                    @foreach (TranslationCatalog::LABELS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="q" placeholder="Поиск по корейскому или английскому" />
            </x-filament::input.wrapper>
            <p class="review-keys">
                <kbd>J</kbd>/<kbd>K</kbd> следующий / предыдущий ·
                <kbd>A</kbd> принять · <kbd>E</kbd> исправить · <kbd>D</kbd> отклонить
            </p>
        </div>

        @if ($list->isEmpty())
            <x-filament::section>
                <p class="lot-empty">
                    @if ($status === 'ai') Все переводы AI проверены.
                    @elseif ($status === 'missing') У всех значений этого поля есть перевод.
                    @else Пусто. @endif
                </p>
            </x-filament::section>
        @else
            <div class="review-grid">
                <x-filament::section class="review-list-section" compact>
                    <ol class="review-list">
                        @foreach ($list as $i => $row)
                            <li>
                                <button type="button" wire:click="select({{ $i }})" @class(['review-item', 'is-current' => $i === $this->index])>
                                    <span class="review-item-kr">{{ $row->kr }}</span>
                                    <span class="review-item-en">
                                        @if ($row instanceof Translation)
                                            {{ $row->en }}
                                            @if ($category === '' || $status !== 'missing')
                                                <span class="review-item-cat">· {{ TranslationCatalog::label($row->category) }}</span>
                                            @endif
                                        @else
                                            {{ LotFormat::number((int) $row->n) }} лот.
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ol>
                    @if ($items instanceof LengthAwarePaginator && $items->hasPages())
                        <x-filament::pagination :paginator="$items" />
                    @endif
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">{{ TranslationCatalog::label($category) }}</x-slot>
                    <x-slot name="afterHeader">
                        @if ($current instanceof Translation)
                            <x-filament::badge :color="$current->source === 'ai' ? 'warning' : 'success'">
                                {{ TranslationCatalog::SOURCES[$current->source] ?? $current->source }}
                            </x-filament::badge>
                        @endif
                    </x-slot>

                    <dl class="review-card">
                        <div>
                            <dt>По-корейски</dt>
                            <dd class="review-big">{{ $current->kr }}</dd>
                        </div>
                        <div>
                            <dt>{{ $current instanceof Translation ? ($current->source === 'ai' ? 'Перевод AI' : 'Перевод') : 'Перевод' }}</dt>
                            <dd class="review-big">{{ $current instanceof Translation ? $current->en : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Встречается</dt>
                            <dd>{{ LotFormat::number($context['usage']) }} {{ \App\Support\JobFormat::plural($context['usage'], 'лот', 'лота', 'лотов') }}</dd>
                        </div>
                        @if ($current instanceof Translation && $current->updated_at)
                            <div>
                                <dt>Изменён</dt>
                                <dd>{{ $current->updated_at->diffForHumans() }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($context['examples']->isNotEmpty())
                        <h3 class="review-sub">Примеры активных лотов</h3>
                        <ul class="review-examples">
                            @foreach ($context['examples'] as $lot)
                                <li>
                                    <a href="{{ \App\Filament\Resources\Lots\LotResource::getUrl('view', ['record' => $lot->id]) }}" target="_blank">
                                        {{ trim(($lot->make_en ?: $lot->make) . ' ' . ($lot->model_en ?: $lot->model_group_en ?: $lot->model)) }}
                                    </a>
                                    <span>{{ collect([$lot->badge, $lot->trim, $lot->year, LotFormat::won($lot->price)])->filter()->implode(' · ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="review-actions">
                        @if ($current instanceof Translation && $current->source === 'ai')
                            <x-filament::button wire:click="acceptCurrent" icon="heroicon-o-check">
                                Принять
                            </x-filament::button>
                        @endif
                        {{ $this->editAction }}
                        @if ($current instanceof Translation)
                            {{ $this->rejectAction }}
                        @endif
                        <span class="review-spacer"></span>
                        @if ($status === 'ai')
                            {{ $this->acceptPageAction }}
                        @endif
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
