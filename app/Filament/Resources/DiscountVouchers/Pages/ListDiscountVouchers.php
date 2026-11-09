<?php

namespace App\Filament\Resources\DiscountVouchers\Pages;

use App\Filament\Resources\DiscountVouchers\DiscountVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiscountVouchers extends ListRecords
{
    protected static string $resource = DiscountVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
