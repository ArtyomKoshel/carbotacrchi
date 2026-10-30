<?php

namespace App\Filament\Pages;

use App\Models\CalculatorSetting;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Calculator\BithumbRate;
use App\Services\Calculator\NbrbRates;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * «Калькулятор»: the defaults of the mini app's landed-cost calculator, the same for the
 * whole team. A manager changes any of them for one car in the lot card; this page sets
 * what every calculation starts from.
 */
class Calculator extends Page
{
    protected string $view = 'filament.pages.calculator';

    protected static ?string $title = 'Калькулятор';

    protected static ?string $navigationLabel = 'Калькулятор';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SEARCH_TEAM;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'calculator';

    public ?array $defaults = [];

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('calculator') ?? false;
    }

    public function mount(): void
    {
        $this->defaultsForm->fill(CalculatorSetting::values());
    }

    public function defaultsForm(Schema $schema): Schema
    {
        $defs = config('calculator.defaults');

        return $schema
            ->statePath('defaults')
            ->components([
                Section::make('Значения по умолчанию')->compact()->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema(collect($defs)->map(fn (array $d, string $key) => TextInput::make($key)
                        ->label($d['label'])
                        ->suffix($d['unit'])
                        ->numeric()->minValue(0)->maxValue(1000000)->required())->values()->all()),
                    Actions::make([
                        Action::make('saveDefaults')->label('Сохранить')->action(fn () => $this->saveDefaults()),
                    ]),
                ]),
            ]);
    }

    public function saveDefaults(): void
    {
        $state = $this->defaultsForm->getState();
        if ((float) ($state['korea_expenses_usd'] ?? 0) > (float) ($state['delivery_usd'] ?? 0)) {
            Notification::make()->title('Расходы в Корее входят в доставку и не могут быть больше неё')->danger()->send();

            return;
        }
        foreach ($state as $key => $value) {
            if (array_key_exists($key, config('calculator.defaults'))) {
                CalculatorSetting::updateOrCreate(['key' => $key], ['value' => (string) (float) $value]);
            }
        }
        Notification::make()->title('Сохранено: новые расчёты в мини-аппе начнутся с этих значений')->success()->send();
    }

    /** @return array{rate: ?float, used: ?float, at: ?string, stale: bool} */
    public function rate(): array
    {
        $r = app(BithumbRate::class)->current();
        $adjust = CalculatorSetting::values()['rate_adjustment_krw'];

        return $r + ['used' => $r['rate'] !== null ? $r['rate'] - $adjust : null];
    }

    /** @return array{usd: ?float, eur: ?float, date: ?string, stale: bool} BYN per 1 USD / EUR */
    public function nbrb(): array
    {
        return app(NbrbRates::class)->current();
    }
}
