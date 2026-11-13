<?php

namespace App\Filament\Resources\StoreHours\Pages;

use App\Filament\Resources\StoreHours\StoreHourResource;
use Filament\Resources\Pages\ListRecords;

class ListStoreHours extends ListRecords
{
    protected static string $resource = StoreHourResource::class;

    /**
     * No create action: the 7 weekday rows are fixed.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
