<?php

namespace App\Filament\Resources\BookingAudits\Pages;

use App\Filament\Resources\BookingAudits\BookingAuditResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBookingAudits extends ManageRecords
{
    protected static string $resource = BookingAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
