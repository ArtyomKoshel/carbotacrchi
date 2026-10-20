<div class="flex flex-col gap-4">
    @if (!empty($intro))
        <p class="panel-intro">{{ $intro }}</p>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
