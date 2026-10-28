<?php

namespace App\Filament\Resources\ProgressReports;

use App\Filament\Resources\ProgressReports\Pages\ManageProgressReports;
use App\Models\Booking;
use App\Models\ProgressReport;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProgressReportResource extends Resource
{
    protected static ?string $model = ProgressReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Progress reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_id')->label('Session / learner')->options(fn (): array => Booking::query()->orderByDesc('starts_at')->get()->mapWithKeys(fn (Booking $booking): array => [$booking->id => $booking->starts_at->timezone('Europe/Bucharest')->format('d M Y H:i').' · '.$booking->customer_name.' · '.$booking->customer_email])->all())->searchable()->required(),
            TextInput::make('title')->maxLength(160)->required(),
            Select::make('overall_level')->label('Overall level')->options(['A1' => 'A1 · Beginner', 'A2' => 'A2 · Elementary', 'B1' => 'B1 · Intermediate', 'B2' => 'B2 · Upper intermediate', 'C1' => 'C1 · Advanced', 'C2' => 'C2 · Proficient']),
            Textarea::make('summary')->rows(4)->required()->maxLength(10000)->columnSpanFull(),
            Textarea::make('strengths')->rows(3)->maxLength(5000),
            Textarea::make('areas_to_improve')->label('Areas to improve')->rows(3)->maxLength(5000),
            Textarea::make('recommendations')->rows(3)->maxLength(5000)->columnSpanFull(),
            DateTimePicker::make('shared_at')->label('Shared with customer')->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('booking.customer_name')->label('Learner')->searchable(),
            TextColumn::make('booking.customer_email')->label('Email')->searchable(),
            TextColumn::make('booking.starts_at')->label('Session date')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->sortable(),
            TextColumn::make('overall_level')->label('Level')->badge()->placeholder('Not assessed'),
            TextColumn::make('author.name')->label('Written by')->placeholder('Unknown'),
            TextColumn::make('shared_at')->label('Shared')->dateTime('d M Y H:i')->placeholder('Draft')->sortable(),
            TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('overall_level')->options(['A1' => 'A1', 'A2' => 'A2', 'B1' => 'B1', 'B2' => 'B2', 'C1' => 'C1', 'C2' => 'C2']),
            SelectFilter::make('shared_at')->label('Sharing status')->options(['shared' => 'Shared', 'draft' => 'Draft'])->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                'shared' => $query->whereNotNull('shared_at'), 'draft' => $query->whereNull('shared_at'), default => $query
            }),
        ])->defaultSort('updated_at', 'desc')->recordActions([
            EditAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => Auth::id()]),
            DeleteAction::make(),
        ])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return true;
    }

    public static function getPages(): array
    {
        return ['index' => ManageProgressReports::route('/')];
    }
}
