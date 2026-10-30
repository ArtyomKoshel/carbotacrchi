<?php

namespace App\Filament\Resources\Lots\Schemas;

use App\Filament\Resources\Lots\LotActions;
use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Lots\Pages\ViewLot;
use App\Models\Lot;
use App\Support\AdminUiLabels;
use App\Support\LotPhotos;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/** Lot card: the same schema in the list's slide-over and on the lot page. */
class LotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'md' => 3])->schema([
                ImageEntry::make('photo')
                    ->hiddenLabel()
                    ->state(fn (Lot $record) => LotPhotos::urls($record->getAttributes())[0] ?? $record->image_url)
                    ->imageWidth('100%')
                    ->imageHeight('auto')
                    // Che168's photos (autoimg.cn) answer 403 to a foreign Referer
                    ->extraImgAttributes(['class' => 'lot-photo', 'alt' => '', 'referrerpolicy' => 'no-referrer'])
                    ->placeholder('Нет фото'),
                Grid::make(2)->columnSpan(['md' => 2])->schema([
                    TextEntry::make('price')->label('Цена')
                        ->formatStateUsing(fn ($state, Lot $record) => LotFormat::money($state, $record->currency))
                        ->size('lg')->weight('semibold'),
                    TextEntry::make('is_active')->label('Статус')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'активен' : 'снят')
                        ->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('year')->label('Год'),
                    TextEntry::make('mileage')->label('Пробег')
                        ->formatStateUsing(fn ($state) => LotFormat::km($state)),
                    TextEntry::make('parsed_at')->label('Обновлён')
                        ->since()->dateTimeTooltip('d.m.Y H:i'),
                    TextEntry::make('created_at')->label('Впервые найден')
                        ->since()->dateTimeTooltip('d.m.Y H:i'),
                    Actions::make([
                        LotActions::reparse(),
                        LotActions::openOnPlatform(),
                        LotActions::openPage(),
                    ])->columnSpanFull(),
                ]),
            ]),

            Tabs::make('lot')
                // Only the lot page keeps its tab in the URL; the slide-over sits on the list's URL
                ->persistTabInQueryString(fn ($livewire) => $livewire instanceof ViewLot ? 'lot-tab' : null)
                ->tabs([
                    Tab::make('Данные')->schema(self::specs()),
                    Tab::make('История')
                        ->badge(fn (Lot $record) => $record->lot_changes_count ?? $record->lotChanges()->count() ?: null)
                        ->schema([
                            ViewEntry::make('history')->hiddenLabel()->view('filament.lots.history'),
                        ]),
                    Tab::make('Осмотр')->schema(self::inspection()),
                    Tab::make('Сырые данные')->schema([
                        ViewEntry::make('raw_data')->hiddenLabel()->view('filament.lots.raw-data'),
                    ]),
                ]),
        ]);
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function specs(): array
    {
        $taxonomy = fn (string $field) => TextEntry::make($field)
            ->label(AdminUiLabels::field($field))
            ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy($field, $state))
            ->placeholder('—');
        $yesNo = fn (string $field, string $label) => TextEntry::make($field)
            ->label($label)
            ->state(fn (Lot $record) => LotFormat::yesNo($record->getAttribute($field)));
        $plain = fn (string $field, ?string $label = null) => TextEntry::make($field)
            ->label($label ?? AdminUiLabels::field($field))
            ->placeholder('—');
        // the reference catalog's level, with what Encar wrote below it
        $level = fn (string $key, string $label, string ...$encar) => TextEntry::make("catalog_{$key}")
            ->label($label)
            ->state(fn (Lot $record) => LotFormat::place($record)[$key])
            ->belowContent(function (Lot $record) use ($encar) {
                $kr = implode(' / ', array_filter(array_map(fn (string $f) => $record->getAttribute($f), $encar)));
                return $kr !== '' ? "как у Encar: {$kr}" : null;
            })
            ->placeholder(fn (Lot $record) => $record->ref_model_id ? 'не указано' : LotFormat::UNPLACED);

        return [
            Section::make('Модель')->columns(3)->compact()->schema([
                $level('make', 'Марка', 'make'),
                $level('model', 'Модель', 'model_group'),
                $level('generation', 'Поколение', 'model'),
                $level('modification', 'Модификация', 'badge_group', 'badge'),
                $level('complectation', 'Комплектация', 'trim'),
                TextEntry::make('catalog_status')->label('Справочник')
                    ->state(fn (Lot $record) => LotFormat::placeStatus($record)),
            ]),
            Section::make('Характеристики')->columns(3)->compact()->schema([
                $taxonomy('fuel'),
                $taxonomy('transmission'),
                TextEntry::make('engine_volume')->label('Объём двигателя')
                    ->formatStateUsing(fn ($state) => LotFormat::liters($state))->placeholder('—'),
                $taxonomy('drive_type'),
                $taxonomy('body_type'),
                $plain('seat_count'),
                $taxonomy('color'),
                TextEntry::make('seat_color')->label('Цвет салона')
                    ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy('seat_color', $state))->placeholder('—'),
            ]),
            Section::make('Состояние')->columns(3)->compact()->schema([
                $yesNo('has_accident', 'Были ДТП'),
                $yesNo('flood_history', 'Затопление'),
                $yesNo('total_loss_history', 'Полная гибель'),
                $plain('owners_count'),
                $plain('insurance_count'),
                TextEntry::make('repair_cost')->label('Ремонт по страховке')
                    ->formatStateUsing(fn ($state, Lot $record) => LotFormat::money($state, $record->currency))->placeholder('—'),
                $plain('lien_status', 'Залог'),
                $plain('seizure_status', 'Арест'),
                TextEntry::make('retail_value')->label('Цена нового')
                    ->formatStateUsing(fn ($state, Lot $record) => LotFormat::money($state, $record->currency))->placeholder('—'),
            ]),
            Section::make('Объявление')->columns(3)->compact()->schema([
                TextEntry::make('source')->label('Площадка')
                    ->formatStateUsing(fn (?string $state) => AdminUiLabels::source($state)),
                TextEntry::make('id')->label('ID лота')->copyable(),
                $plain('sell_type'),
                TextEntry::make('vin')->label('VIN')->fontFamily('mono')->copyable()->placeholder('—'),
                $plain('plate_number'),
                $plain('location'),
                $plain('first_reg_date'),
                TextEntry::make('listed_at')->label('Опубликован')->date('d.m.Y')->placeholder('—'),
                TextEntry::make('history_checked_at')->label('История проверена')
                    ->since()->dateTimeTooltip('d.m.Y H:i')->placeholder('—'),
            ]),
        ];
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function inspection(): array
    {
        $yesNo = fn (string $field, string $label) => TextEntry::make("inspection.{$field}")
            ->label($label)
            ->state(fn (Lot $record) => LotFormat::yesNo($record->inspection?->getAttribute($field)));

        return [
            TextEntry::make('no_inspection')
                ->hiddenLabel()
                ->state('Парсер не получил отчёт осмотра для этого лота.')
                ->visible(fn (Lot $record) => $record->inspection === null),
            Section::make('Отчёт')->columns(3)->compact()
                ->visible(fn (Lot $record) => $record->inspection !== null)
                ->schema([
                    TextEntry::make('inspection.cert_no')->label('Номер отчёта')->copyable()->placeholder('—'),
                    TextEntry::make('inspection.inspection_date')->label('Дата осмотра')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('inspection.valid_until')->label('Действует до')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('inspection.first_registration')->label('Первая регистрация')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('inspection.inspection_mileage')->label('Пробег при осмотре')
                        ->formatStateUsing(fn ($state) => LotFormat::km($state))->placeholder('—'),
                    TextEntry::make('inspection.report_url')->label('Отчёт на площадке')
                        ->formatStateUsing(fn () => 'Открыть')
                        ->url(fn (Lot $record) => $record->inspection?->report_url, shouldOpenInNewTab: true)
                        ->color('primary')
                        ->placeholder('—'),
                ]),
            Section::make('Повреждения')->columns(3)->compact()
                ->visible(fn (Lot $record) => $record->inspection !== null)
                ->schema([
                    $yesNo('has_accident', 'ДТП'),
                    $yesNo('has_outer_damage', 'Кузовные повреждения'),
                    $yesNo('has_flood', 'Затопление'),
                    $yesNo('has_fire', 'Пожар'),
                    $yesNo('has_tuning', 'Тюнинг'),
                    $yesNo('has_recall', 'Отзывная кампания'),
                    TextEntry::make('inspection.accident_detail')->label('Страховые случаи')
                        ->formatStateUsing(fn (?string $state) => new HtmlString(nl2br(e(trim((string) $state)))))
                        ->columnSpanFull()->placeholder('—'),
                    TextEntry::make('inspection.outer_detail')->label('Кузовные элементы')
                        ->formatStateUsing(fn (?string $state) => new HtmlString(nl2br(e(trim((string) $state)))))
                        ->columnSpanFull()->placeholder('—'),
                ]),
        ];
    }
}
