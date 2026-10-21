<?php

namespace App\Filament\Resources\EncarOptions\Pages;

use App\Filament\Resources\EncarOptions\EncarOptionResource;
use Filament\Resources\Pages\ListRecords;

class ListEncarOptions extends ListRecords
{
    protected static string $resource = EncarOptionResource::class;

    protected static ?string $title = 'Опции Encar';
}
