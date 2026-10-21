<?php

namespace App\Filament\Resources\BotUsers\Pages;

use App\Filament\Resources\BotUsers\BotUserResource;
use Filament\Resources\Pages\ListRecords;

class ListBotUsers extends ListRecords
{
    protected static string $resource = BotUserResource::class;

    protected static ?string $title = 'Подборщики';
}
