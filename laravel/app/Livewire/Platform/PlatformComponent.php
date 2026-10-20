<?php

namespace App\Livewire\Platform;

use App\Providers\Filament\AdminPanelProvider;
use App\Support\Platforms;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** A tab of the platform page. Checks the tab's page permission on every request. */
abstract class PlatformComponent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** admin_page_permissions key of this tab */
    protected const PAGE_KEY = '';

    #[Locked]
    public string $source = '';

    public function boot(): void
    {
        abort_unless(AdminPanelProvider::admin()?->canSeePage(static::PAGE_KEY), 403);
    }

    public function mount(string $source): void
    {
        abort_unless(Platforms::exists($source), 404);
        $this->source = $source;
    }

    protected function adminName(): string
    {
        return 'admin:' . (AdminPanelProvider::admin()?->username ?? '?');
    }
}
