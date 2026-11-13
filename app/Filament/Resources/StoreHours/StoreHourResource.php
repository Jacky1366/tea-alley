<?php

namespace App\Filament\Resources\StoreHours;

use App\Filament\Resources\StoreHours\Pages\EditStoreHour;
use App\Filament\Resources\StoreHours\Pages\ListStoreHours;
use App\Filament\Resources\StoreHours\Schemas\StoreHourForm;
use App\Filament\Resources\StoreHours\Tables\StoreHoursTable;
use App\Models\StoreHour;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit-only: the 7 weekday rows always exist, so there is no create page and no delete action.
 */
class StoreHourResource extends Resource
{
    protected static ?string $model = StoreHour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Store hours';

    protected static ?string $modelLabel = 'store hours';

    protected static ?string $pluralModelLabel = 'store hours';

    protected static ?int $navigationSort = 10;

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof StoreHour
            ? StoreHour::DAY_NAMES[$record->day_of_week]
            : 'Store hours';
    }

    public static function form(Schema $schema): Schema
    {
        return StoreHourForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoreHoursTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreHours::route('/'),
            'edit' => EditStoreHour::route('/{record}/edit'),
        ];
    }
}
