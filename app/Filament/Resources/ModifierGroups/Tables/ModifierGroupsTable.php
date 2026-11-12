<?php

namespace App\Filament\Resources\ModifierGroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_en')
                    ->label('Name (EN)')
                    ->searchable(),
                TextColumn::make('name_zh')
                    ->label('Name (ZH)')
                    ->searchable(),
                IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),
                TextColumn::make('min_select')
                    ->label('Min')
                    ->numeric(),
                TextColumn::make('max_select')
                    ->label('Max')
                    ->numeric(),
                TextColumn::make('options_count')
                    ->label('Options')
                    ->counts('options'),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
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
