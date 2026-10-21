<?php

namespace App\Filament\Resources\Lots\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Lots\LotResource;
use App\Models\Lot;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** A lot on its own page: same card as the list's slide-over, with a link to share. */
class ViewLot extends ViewRecord
{
    protected static string $resource = LotResource::class;

    public function getTitle(): string | Htmlable
    {
        /** @var Lot $lot */
        $lot = $this->getRecord();

        return LotFormat::title($lot);
    }

    public function getSubheading(): string | Htmlable | null
    {
        /** @var Lot $lot */
        $lot = $this->getRecord();

        return LotFormat::subtitle($lot);
    }
}
