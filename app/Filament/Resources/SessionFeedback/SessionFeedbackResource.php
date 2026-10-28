<?php

namespace App\Filament\Resources\SessionFeedback;

use App\Filament\Resources\SessionFeedback\Pages\ManageSessionFeedback;
use App\Models\Booking;
use App\Models\SessionFeedback;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SessionFeedbackResource extends Resource
{
    protected static ?string $model = SessionFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Session feedback';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_id')
                ->label('Session / learner')
                ->options(fn (): array => Booking::query()->orderByDesc('starts_at')->get()->mapWithKeys(fn (Booking $booking): array => [
                    $booking->id => $booking->starts_at->timezone('Europe/Bucharest')->format('d M Y H:i').' · '.$booking->customer_name.' · '.$booking->customer_email,
                ])->all())
                ->searchable()
                ->required(),
            Select::make('metric_key')->label('Metric')->options([
                'vocabulary' => 'Vocabulary',
                'pronunciation' => 'Pronunciation',
                'fluency' => 'Fluency',
                'grammar' => 'Grammar',
                'confidence' => 'Confidence',
            ])->required(),
            TextInput::make('baseline_score')->label('Baseline score (0–100)')->numeric()->minValue(0)->maxValue(100),
            TextInput::make('current_score')->label('Current score (0–100)')->numeric()->minValue(0)->maxValue(100),
            TextInput::make('potential_score')->label('Potential score (0–100)')->numeric()->minValue(0)->maxValue(100),
            Textarea::make('feedback')->label('Teacher feedback')->rows(4)->maxLength(5000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('booking.customer_name')->label('Learner')->searchable(),
            TextColumn::make('booking.customer_email')->label('Email')->searchable(),
            TextColumn::make('booking.starts_at')->label('Session')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->sortable(),
            TextColumn::make('metric_key')->label('Metric')->formatStateUsing(fn (string $state): string => ucfirst($state))->badge(),
            TextColumn::make('baseline_score')->label('Baseline'),
            TextColumn::make('current_score')->label('Current'),
            TextColumn::make('potential_score')->label('Potential'),
            TextColumn::make('updated_at')->dateTime('d M Y H:i')->sortable(),
        ])->defaultSort('updated_at', 'desc')->recordActions([
            EditAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'recorded_by' => Auth::id()]),
            DeleteAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([DeleteBulkAction::make()]),
        ]);
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
        return ['index' => ManageSessionFeedback::route('/')];
    }
}
