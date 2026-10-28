<?php

namespace App\Filament\Resources\SessionFeedback\Pages;

use App\Filament\Resources\SessionFeedback\SessionFeedbackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSessionFeedback extends ManageRecords
{
    protected static string $resource = SessionFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
