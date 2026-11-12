<?php

namespace App\Filament\Resources\ModifierGroups\RelationManagers;

use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    public function form(Schema $schema): Schema
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
                // Entered in dollars; converted to cents on the server when saved.
                TextInput::make('price_delta_cents')
                    ->label('Extra charge')
                    ->prefix('+$')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99.99)
                    ->rule('decimal:0,2')
                    ->default('0.00')
                    ->formatStateUsing(fn (string|int|null $state): ?string => is_int($state) ? Money::toDollars($state) : $state)
                    ->dehydrateStateUsing(fn (string|int|float $state): int => Money::toCents($state)),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_default')
                    ->label('Selected by default'),
                Toggle::make('is_available')
                    ->label('Available')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name_en')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_en')
                    ->label('Name (EN)')
                    ->searchable(),
                TextColumn::make('name_zh')
                    ->label('Name (ZH)'),
                TextColumn::make('price_delta_cents')
                    ->label('Extra charge')
                    ->formatStateUsing(fn (int $state): string => '+'.Money::format($state)),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
