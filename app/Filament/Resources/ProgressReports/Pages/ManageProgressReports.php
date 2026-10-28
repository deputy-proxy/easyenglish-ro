<?php

namespace App\Filament\Resources\ProgressReports\Pages;

use App\Filament\Resources\ProgressReports\ProgressReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageProgressReports extends ManageRecords
{
    protected static string $resource = ProgressReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => Auth::id()])];
    }
}
