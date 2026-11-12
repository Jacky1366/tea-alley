<?php

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Models\MenuItem;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MenuItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name_en')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name_en')
                    ->label('Name (English)')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_zh')
                    ->label('Name (Chinese)')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->columnSpanFull(),
                // Entered in dollars; validated as dollars, then converted to cents on the server when saved.
                TextInput::make('price_cents')
                    ->label('Price')
                    ->prefix('$')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999.99)
                    ->rule('decimal:0,2')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toDollars($state))
                    ->dehydrateStateUsing(fn (string|int|float $state): int => Money::toCents($state)),
                Select::make('tax_category')
                    ->options([
                        MenuItem::TAX_STANDARD => 'Standard',
                        MenuItem::TAX_CARBONATED => 'Carbonated',
                    ])
                    ->required()
                    ->default(MenuItem::TAX_STANDARD),
                TextInput::make('prep_minutes')
                    ->label('Prep time (minutes)')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(60)
                    ->default(3),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_available')
                    ->label('Available')
                    ->default(true),
                // Livewire stores uploads under a random hash name; we never keep the client's filename.
                FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->disk('public')
                    ->directory('menu-items')
                    ->visibility('public')
                    ->columnSpanFull(),
                CheckboxList::make('modifierGroups')
                    ->label('Modifier groups')
                    ->relationship('modifierGroups', 'name_en')
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
