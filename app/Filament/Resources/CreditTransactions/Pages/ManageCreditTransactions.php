<?php

namespace App\Filament\Resources\CreditTransactions\Pages;

use App\Filament\Resources\CreditTransactions\CreditTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCreditTransactions extends ManageRecords
{
    protected static string $resource = CreditTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
