<?php

namespace App\Filament\Resources\StoreHours\Pages;

use App\Filament\Resources\StoreHours\StoreHourResource;
use Filament\Resources\Pages\EditRecord;

class EditStoreHour extends EditRecord
{
    protected static string $resource = StoreHourResource::class;

    /**
     * No delete action: the 7 weekday rows are fixed.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * A closed day has no hours, so drop any times left in the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['is_closed'])) {
            $data['opens_at'] = null;
            $data['closes_at'] = null;
        }

        return $data;
    }
}
