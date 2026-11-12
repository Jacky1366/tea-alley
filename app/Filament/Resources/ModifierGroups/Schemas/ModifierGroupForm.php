<?php

namespace App\Filament\Resources\ModifierGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ModifierGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_en')
                    ->label('Name (English)')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_zh')
                    ->label('Name (Chinese)')
                    ->required()
                    ->maxLength(255),
                TextInput::make('min_select')
                    ->label('Minimum selections')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(20)
                    ->default(0),
                TextInput::make('max_select')
                    ->label('Maximum selections')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(20)
                    ->gte('min_select')
                    ->default(1),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_required')
                    ->label('Required'),
            ]);
    }
}
