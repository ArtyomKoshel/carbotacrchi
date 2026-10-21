<x-filament-panels::page>
    <x-filament::tabs label="Разделы площадки">
        @foreach ($this->visibleTabs() as $key => $label)
            <x-filament::tabs.item
                :active="$tab === $key"
                wire:click="$set('tab', '{{ $key }}')"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @switch ($tab)
        @case('runs')
            @livewire(\App\Livewire\Platform\Runs::class, ['source' => $source], key('runs-' . $source))
            @break
        @case('schedule')
            @livewire(\App\Livewire\Platform\Schedule::class, ['source' => $source], key('schedule-' . $source))
            @break
        @case('rules')
            @livewire(\App\Livewire\Platform\Rules::class, ['source' => $source], key('rules-' . $source))
            @break
        @case('skips')
            @livewire(\App\Livewire\Platform\SkipLog::class, ['source' => $source], key('skips-' . $source))
            @break
        @case('fields')
            @livewire(\App\Livewire\Platform\Fields::class, ['source' => $source], key('fields-' . $source))
            @break
    @endswitch
</x-filament-panels::page>
