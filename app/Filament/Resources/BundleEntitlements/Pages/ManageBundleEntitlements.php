<?php

namespace App\Filament\Resources\BundleEntitlements\Pages;

use App\Filament\Resources\BundleEntitlements\BundleEntitlementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBundleEntitlements extends ManageRecords
{
    protected static string $resource = BundleEntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
