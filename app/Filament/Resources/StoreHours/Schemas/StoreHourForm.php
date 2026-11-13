<?php

namespace App\Filament\Resources\StoreHours\Schemas;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class StoreHourForm
{
    public static function configure(Schema $schema): Schema
    {
        $isOpenDay = fn (Get $get): bool => ! $get('is_closed');

        return $schema
            ->components([
                Toggle::make('is_closed')
                    ->label('Closed all day')
                    ->live()
                    ->columnSpanFull(),
                // Wall-clock times in the display timezone. Pinning the picker to the app
                // timezone stops Filament from converting them like UTC timestamps.
                TimePicker::make('opens_at')
                    ->label('Opens at')
                    ->seconds(false)
                    ->timezone(fn (): string => config()->string('app.timezone'))
                    ->required($isOpenDay),
                TimePicker::make('closes_at')
                    ->label('Closes at')
                    ->seconds(false)
                    ->timezone(fn (): string => config()->string('app.timezone'))
                    ->required($isOpenDay)
                    ->after('opens_at')
                    ->helperText('Overnight hours are not supported: closing must be later the same day.'),
                TextInput::make('ordering_cutoff_minutes')
                    ->label('Online ordering cutoff')
                    ->helperText('Online ordering stops this many minutes before closing.')
                    ->suffix('minutes')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1439)
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $minutes = self::openMinutes($get('opens_at'), $get('closes_at'));

                        if ($get('is_closed') || $minutes === null || ! is_numeric($value)) {
                            return;
                        }

                        if ((int) $value >= $minutes) {
                            $fail("The cutoff must be shorter than the day's opening hours ({$minutes} minutes).");
                        }
                    }),
            ]);
    }

    /**
     * Minutes between opening and closing, or null if either time is missing,
     * invalid, or closing isn't after opening (the closes_at rule reports that).
     */
    private static function openMinutes(mixed $opensAt, mixed $closesAt): ?int
    {
        if (! is_string($opensAt) || ! is_string($closesAt) || $opensAt === '' || $closesAt === '') {
            return null;
        }

        try {
            $opens = Carbon::parse($opensAt);
            $closes = Carbon::parse($closesAt);
        } catch (\Throwable) {
            return null;
        }

        return $closes->gt($opens) ? (int) $opens->diffInMinutes($closes) : null;
    }
}
