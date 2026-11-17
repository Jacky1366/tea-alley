<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * No create action: entries are written only by AuditObserver.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
