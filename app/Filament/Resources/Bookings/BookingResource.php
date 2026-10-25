<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\ManageBookings;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Services\Booking\BookingService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Bookings';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Appointment')->schema([
                TextEntry::make('public_reference')->label('Reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('starts_at')->label('Starts (Bucharest)')->dateTime('d M Y H:i')->timezone('Europe/Bucharest'),
                TextEntry::make('ends_at')->label('Ends (Bucharest)')->dateTime('d M Y H:i')->timezone('Europe/Bucharest'),
                TextEntry::make('hold_expires_at')->dateTime('d M Y H:i:s'),
                TextEntry::make('accepted_policy_version')->label('Accepted policy version'),
            ])->columns(2),
            Section::make('Learner')->schema([
                TextEntry::make('customer_name'), TextEntry::make('customer_email'), TextEntry::make('customer_phone'),
                TextEntry::make('english_level'), TextEntry::make('session_goal'), TextEntry::make('notes')->placeholder('No notes supplied'),
            ])->columns(2),
            Section::make('Entitlement and audit')->schema([
                TextEntry::make('entitlement.status')->label('Bundle status')->placeholder('Free introduction'),
                TextEntry::make('entitlement.credits_remaining')->label('Remaining credits'),
                TextEntry::make('entitlement.expires_at')->label('Bundle expiry')->dateTime('d M Y H:i'),
                TextEntry::make('latest_audit_action')->label('Latest audit event')->state(fn (Booking $record): string => $record->audits()->latest()->value('action') ?? 'No audit events'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_reference')->label('Reference')->searchable(),
            TextColumn::make('customer_name')->searchable(),
            TextColumn::make('customer_email')->searchable(),
            TextColumn::make('starts_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->sortable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'confirmed' => 'success', 'pending', 'refund_pending' => 'warning', 'expired', 'cancelled', 'no_show', 'failed' => 'danger', default => 'gray'
            }),
            TextColumn::make('entitlement.credits_remaining')->label('Credits left')->placeholder('Free intro'),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending hold', 'confirmed' => 'Confirmed', 'refund_pending' => 'Refund pending', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'])])->defaultSort('starts_at')->recordActions([
            ViewAction::make(),
            Action::make('confirm')->label('Confirm hold')->icon(Heroicon::OutlinedCheckCircle)->color('success')->requiresConfirmation()->visible(fn (Booking $record): bool => $record->status === 'pending' && $record->hold_expires_at?->isFuture())->action(function (Booking $record): void {
                app(BookingService::class)->confirm($record->id);
                BookingAudit::query()->create(['booking_id' => $record->id, 'actor_id' => Auth::id(), 'action' => 'staff.booking_confirmed']);
            }),
            Action::make('cancelAtCustomerRequest')->label('Cancel at customer request')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible(fn (Booking $record): bool => $record->status === 'confirmed' && $record->starts_at->isFuture())->schema([
                Textarea::make('reason')->label('Customer request / reason')->minLength(10)->maxLength(500)->required(),
            ])->requiresConfirmation()->action(function (Booking $record, array $data): void {
                app(BookingService::class)->cancelAtCustomerRequest($record->id, (int) Auth::id(), $data['reason']);
            }),
            Action::make('reschedule')->label('Reschedule')->icon(Heroicon::OutlinedCalendar)->color('warning')->visible(fn (Booking $record): bool => $record->status === 'confirmed' && $record->starts_at->isFuture())->schema([
                DateTimePicker::make('starts_at')->label('New start time (Bucharest)')->timezone('Europe/Bucharest')->seconds(false)->required(),
                Textarea::make('reason')->label('Reason for audit log')->minLength(10)->maxLength(500)->required(),
            ])->requiresConfirmation()->action(function (Booking $record, array $data): void {
                app(BookingService::class)->rescheduleByStaff($record->id, CarbonImmutable::parse($data['starts_at'], 'Europe/Bucharest'), (int) Auth::id(), $data['reason']);
            }),
            Action::make('teacherCancellation')->label('Teacher cancellation')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible(fn (Booking $record): bool => $record->status === 'confirmed' && $record->starts_at->isFuture())->schema([
                Textarea::make('reason')->label('Teacher cancellation reason')->minLength(10)->maxLength(500)->required(),
            ])->requiresConfirmation()->action(function (Booking $record, array $data): void {
                app(BookingService::class)->cancelByTeacher($record->id, (int) Auth::id(), $data['reason']);
            }),
            Action::make('noShow')->label('Record no-show')->icon(Heroicon::OutlinedExclamationCircle)->color('danger')->visible(fn (Booking $record): bool => $record->status === 'confirmed' && $record->starts_at->lessThanOrEqualTo(now()))->schema([
                Textarea::make('reason')->label('No-show evidence / reason')->minLength(10)->maxLength(500)->required(),
            ])->requiresConfirmation()->action(function (Booking $record, array $data): void {
                app(BookingService::class)->markNoShow($record->id, (int) Auth::id(), $data['reason']);
            }),
        ]);
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
        return ['index' => ManageBookings::route('/')];
    }
}
