<?php

namespace App\Filament\Resources\AvailabilityExceptions\Pages;

use App\Filament\Resources\AvailabilityExceptions\AvailabilityExceptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAvailabilityExceptions extends ManageRecords
{
    protected static string $resource = AvailabilityExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
