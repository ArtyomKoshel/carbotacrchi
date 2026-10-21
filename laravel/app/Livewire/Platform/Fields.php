<?php

namespace App\Livewire\Platform;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\FieldCoverageStat;
use App\Services\FieldRegistryService;
use App\Support\AdminUiLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** «Поля»: how filled each lot field is on this platform (field_coverage_stats). */
class Fields extends PlatformComponent implements HasTable
{
    use InteractsWithTable;

    protected const PAGE_KEY = 'fields';

    public function render(): View
    {
        $computed = FieldCoverageStat::where('source', $this->source)->max('computed_at');

        return view('livewire.platform.table', [
            'intro' => 'Доля лотов, у которых поле заполнено. '
                . ($computed ? 'Посчитано ' . \Carbon\Carbon::parse($computed)->diffForHumans() . '.' : 'Ещё не считалось: нажмите «Пересчитать».'),
        ]);
    }

    public function table(Table $table): Table
    {
        $registry = app(FieldRegistryService::class);
        $category = fn (string $name) => $registry->get($name)['category'] ?? 'other';

        return $table
            ->query(FieldCoverageStat::query()->where('source', $this->source))
            ->defaultSort('coverage_pct')
            ->columns([
                TextColumn::make('field_name')->label('Поле')
                    ->formatStateUsing(fn (string $state) => AdminUiLabels::field($state))
                    ->description(fn (FieldCoverageStat $record) => $record->field_name)
                    ->searchable(),
                TextColumn::make('category')->label('Группа')
                    ->state(fn (FieldCoverageStat $record) => $category($record->field_name))
                    ->toggleable(isToggledHiddenByDefault: true),
                ViewColumn::make('coverage_pct')->label('Заполнено')->sortable()
                    ->view('livewire.platform.partials.coverage-bar'),
                TextColumn::make('filled_lots')->label('Лотов')->alignEnd()
                    ->formatStateUsing(fn (FieldCoverageStat $record) => LotFormat::number($record->filled_lots) . ' из ' . LotFormat::number($record->total_lots)),
            ])
            ->filters([
                SelectFilter::make('level')->label('Заполненность')
                    ->options(['low' => 'меньше 50 %', 'mid' => '50–95 %', 'high' => 'от 95 %'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'low'  => $query->where('coverage_pct', '<', 50),
                        'mid'  => $query->whereBetween('coverage_pct', [50, 94.99]),
                        'high' => $query->where('coverage_pct', '>=', 95),
                        default => $query,
                    }),
            ])
            ->headerActions([
                Action::make('recompute')
                    ->label('Пересчитать')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Пересчитать заполненность полей?')
                    ->modalDescription('Обновит схему полей из парсера и пройдёт по всем лотам площадки одним запросом. Обычно это занимает несколько секунд.')
                    ->action(fn () => $this->recompute()),
            ])
            ->paginated(false)
            ->emptyStateHeading('Заполненность ещё не считалась');
    }

    /**
     * What the old /admin/fields «Recompute» did, for this source. A failed schema
     * export (no Python in this environment) only warns: coverage is computed
     * from the last exported storage/app/fields.json.
     */
    public function recompute(): void
    {
        $export = Artisan::call('parser:export-fields');
        if ($export !== 0) {
            Log::warning('parser:export-fields failed', ['code' => $export, 'output' => Artisan::output()]);
        }

        $code = Artisan::call('fields:compute-coverage', ['--source' => $this->source]);
        if ($code !== 0) {
            Log::error('fields:compute-coverage failed', ['code' => $code, 'output' => Artisan::output()]);
            Notification::make()->title('Не удалось пересчитать')->body("Код {$code}, подробности в логе приложения.")->danger()->send();

            return;
        }

        Notification::make()
            ->title('Заполненность пересчитана')
            ->body($export !== 0 ? 'Схему полей обновить не удалось (парсер недоступен), использована последняя сохранённая.' : null)
            ->success()
            ->send();
    }
}
