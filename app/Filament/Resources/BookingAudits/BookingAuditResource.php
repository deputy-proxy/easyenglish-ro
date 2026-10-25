<?php

namespace App\Filament\Resources\BookingAudits;

use App\Filament\Resources\BookingAudits\Pages\ManageBookingAudits;
use App\Models\BookingAudit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingAuditResource extends Resource
{
    protected static ?string $model = BookingAudit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i:s')->sortable(),
            TextColumn::make('action')->badge()->searchable(),
            TextColumn::make('booking.public_reference')->label('Booking')->searchable()->placeholder('Not booking-specific'),
            TextColumn::make('entitlement.customer_email')->label('Customer'),
            TextColumn::make('subject_type')->label('Subject'),
            TextColumn::make('subject_id')->label('Subject ID'),
            TextColumn::make('actor.name')->label('Staff actor')->placeholder('System'),
            TextColumn::make('reason')->limit(60),
        ])->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageBookingAudits::route('/')];
    }
}
