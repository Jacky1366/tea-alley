<?php

namespace App\Filament\Resources\MenuItems\Tables;

use App\Models\MenuItem;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square()
                    // Hidden on phones so the Available toggle fits without scrolling the table.
                    ->visibleFrom('md'),
                TextColumn::make('name_en')
                    ->label('Name (EN)')
                    ->searchable(),
                // One click marks a drink sold out (86). Disabled without the update ability; Filament
                // also refuses the write on the server when the column is disabled.
                // Right after the name, so it's on screen without scrolling the table on a phone.
                ToggleColumn::make('is_available')
                    ->label('Available')
                    ->disabled(fn (MenuItem $record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('name_zh')
                    ->label('Name (ZH)')
                    ->searchable(),
                TextColumn::make('category.name_en')
                    ->label('Category'),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable(),
                TextColumn::make('tax_category')
                    ->label('Tax')
                    ->badge(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('prep_minutes')
                    ->label('Prep (min)')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name_en')
                    ->preload(),
                Filter::make('sold_out')
                    ->label('Sold out')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('is_available', false)),
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
