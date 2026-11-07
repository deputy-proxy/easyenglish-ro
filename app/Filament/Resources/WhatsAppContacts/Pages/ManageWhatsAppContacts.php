<?php

namespace App\Filament\Resources\WhatsAppContacts\Pages;

use App\Filament\Resources\WhatsAppContacts\WhatsAppContactResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWhatsAppContacts extends ManageRecords
{
    protected static string $resource = WhatsAppContactResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
