<?php

namespace App\Filament\Resources\LotChanges\Pages;

use App\Filament\Resources\LotChanges\LotChangeResource;
use App\Filament\Widgets\LotChangesSummaryWidget;
use Filament\Resources\Pages\ListRecords;

class ListLotChanges extends ListRecords
{
    protected static string $resource = LotChangeResource::class;

    protected static ?string $title = 'Изменения лотов';

    protected function getHeaderWidgets(): array
    {
        return [LotChangesSummaryWidget::class];
    }
}
