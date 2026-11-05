<?php

namespace App\Filament\Resources\CallEvents\Pages;

use App\Filament\Resources\CallEvents\CallEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCallEvents extends ManageRecords
{
    protected static string $resource = CallEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
