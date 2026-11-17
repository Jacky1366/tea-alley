<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Support\AuditChanges;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    /** Audited models, as the admin sees them. */
    public const TYPES = [
        MenuItem::class => 'Drink',
        ModifierOption::class => 'Modifier option',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->timezone(config()->string('app.display_timezone'))
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System'),
                TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === AuditLog::DELETED ? 'danger' : 'warning'),
                TextColumn::make('auditable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? class_basename($state)),
                TextColumn::make('auditable_label')
                    ->label('Record')
                    ->searchable(),
                TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (AuditLog $record): array => AuditChanges::lines($record))
                    ->listWithLineBreaks(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options([AuditLog::UPDATED => 'Updated', AuditLog::DELETED => 'Deleted']),
                SelectFilter::make('auditable_type')
                    ->label('Type')
                    ->options(self::TYPES),
            ]);
    }
}
