<x-filament-widgets::widget>
    <x-filament::section heading="Открытые инциденты">
        @forelse ($events as $event)
            <div class="incident">
                <div class="incident-head">
                    <x-filament::badge color="warning">{{ $types[$event->type] ?? $event->type }}</x-filament::badge>
                    <time class="incident-time" datetime="{{ $event->opened_at?->toIso8601String() }}" title="{{ $event->opened_at?->format('d.m.Y H:i') }}">
                        с {{ $event->opened_at?->diffForHumans() }}
                    </time>
                </div>
                <code class="incident-key">{{ $event->event_key }}</code>
            </div>
        @empty
            <p class="lot-empty">Нет: зависших задач, падений базы и низких балансов не замечено.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
