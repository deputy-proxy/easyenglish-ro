<?php

namespace App\Filament\Resources\AvailabilityRules\Pages;

use App\Filament\Resources\AvailabilityRules\AvailabilityRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAvailabilityRules extends ManageRecords
{
    protected static string $resource = AvailabilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
