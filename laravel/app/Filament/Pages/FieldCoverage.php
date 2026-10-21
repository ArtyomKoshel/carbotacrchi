<?php

namespace App\Filament\Pages;

use App\Models\FieldCoverageStat;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\FieldRegistryService;
use App\Support\AdminUiLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** «Покрытие полей»: fields × platforms from field_coverage_stats. */
class FieldCoverage extends Page
{
    /** Below this a field is shown as a problem (status color). */
    public const LOW_PCT = 50;

    protected string $view = 'filament.pages.field-coverage';

    protected static ?string $title = 'Покрытие полей';

    protected static ?string $navigationLabel = 'Покрытие полей';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'field-coverage';

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('fields') ?? false;
    }

    /**
     * @return array{sources: list<string>, groups: array<string, array<string, array<string, FieldCoverageStat>>>, computed: ?string}
     */
    public function matrix(): array
    {
        $stats = FieldCoverageStat::orderBy('field_name')->get();
        $registry = app(FieldRegistryService::class);
        $categoryLabels = [
            'identity' => 'Модель и учёт', 'price' => 'Цена', 'condition' => 'Состояние', 'specs' => 'Характеристики',
            'dealer' => 'Продавец', 'legal' => 'Юридическое', 'sales' => 'Продажа', 'media' => 'Фото и ссылки', 'other' => 'Прочее',
        ];

        $groups = [];
        foreach ($stats as $s) {
            $category = $registry->get($s->field_name)['category'] ?? 'other';
            $groups[$categoryLabels[$category] ?? $category][$s->field_name][$s->source] = $s;
        }
        ksort($groups);

        return [
            'sources'  => $stats->pluck('source')->unique()->sort()->values()->all(),
            'groups'   => $groups,
            'computed' => $stats->max('computed_at')?->diffForHumans(),
        ];
    }

    public static function fieldLabel(string $name): string
    {
        return AdminUiLabels::field($name);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recompute')
                ->label('Пересчитать')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Пересчитать покрытие полей?')
                ->modalDescription('Один проход по активным лотам каждой площадки. Обычно несколько секунд.')
                ->action(function () {
                    $code = Artisan::call('fields:compute-coverage');
                    if ($code !== 0) {
                        Log::error('fields:compute-coverage failed', ['code' => $code, 'output' => Artisan::output()]);
                        Notification::make()->title('Не удалось пересчитать')->body("Код {$code}, подробности в логе приложения.")->danger()->send();

                        return;
                    }
                    Notification::make()->title('Покрытие пересчитано')->success()->send();
                }),
        ];
    }
}
