<?php

namespace App\Filament\Resources\StoreHours\Tables;

use App\Models\StoreHour;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class StoreHoursTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('day_of_week')
            ->paginated(false)
            ->columns([
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => StoreHour::DAY_NAMES[$state]),
                IconColumn::make('is_closed')
                    ->label('Closed')
                    ->boolean(),
                TextColumn::make('opens_at')
                    ->label('Opens')
                    ->formatStateUsing(fn (string $state): string => self::wallClock($state))
                    ->placeholder('—'),
                TextColumn::make('closes_at')
                    ->label('Closes')
                    ->formatStateUsing(fn (string $state): string => self::wallClock($state))
                    ->placeholder('—'),
                TextColumn::make('ordering_cutoff_minutes')
                    ->label('Ordering cutoff')
                    ->suffix(' min'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * "11:00:00" -> "11:00 AM". A plain time of day: no timezone conversion.
     */
    private static function wallClock(string $time): string
    {
        return Carbon::parse($time)->format('g:i A');
    }
}
