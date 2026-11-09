<?php

namespace App\Filament\Resources\DiscountVouchers\Pages;

use App\Filament\Resources\DiscountVouchers\DiscountVoucherResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscountVoucher extends EditRecord
{
    protected static string $resource = DiscountVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
