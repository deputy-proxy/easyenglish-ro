<?php

namespace App\Filament\Resources\BundleDefinitions\Pages;

use App\Filament\Resources\BundleDefinitions\BundleDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBundleDefinitions extends ManageRecords
{
    protected static string $resource = BundleDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
