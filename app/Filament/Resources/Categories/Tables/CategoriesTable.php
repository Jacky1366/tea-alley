<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_en')
                    ->label('Name (EN)')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_zh')
                    ->label('Name (ZH)')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('menu_items_count')
                    ->label('Drinks')
                    ->counts('menuItems')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(fn (DeleteAction $action, Category $record) => CategoryResource::cancelDeleteIfCategoriesHaveDrinks($action, collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(fn (DeleteBulkAction $action, Collection $records) => CategoryResource::cancelDeleteIfCategoriesHaveDrinks($action, $records)),
                ]),
            ]);
    }
}
