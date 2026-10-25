<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\Resources\NotificationDeliveries\Pages\ManageNotificationDeliveries;
use App\Models\BookingAudit;
use App\Models\NotificationDelivery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Notification delivery';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['booking', 'entitlement', 'refund.payment.entitlement']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i:s')->sortable(),
            TextColumn::make('event')->badge()->searchable(),
            TextColumn::make('channel')->badge(),
            TextColumn::make('recipient')->state(fn (NotificationDelivery $record): string => $record->booking->customer_email
                ?? $record->entitlement->customer_email
                ?? $record->refund->payment->entitlement->customer_email
                ?? 'Recipient unavailable'),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'sent' => 'success', 'failed' => 'danger', 'sending', 'queued' => 'warning', 'skipped' => 'gray', default => 'gray',
            })->sortable(),
            TextColumn::make('attempts')->numeric()->sortable(),
            TextColumn::make('next_attempt_at')->dateTime('d M Y H:i:s')->placeholder('Not scheduled'),
            TextColumn::make('sent_at')->dateTime('d M Y H:i:s')->placeholder('Not sent'),
            TextColumn::make('last_error')->limit(70)->placeholder('No error recorded'),
        ])->filters([
            SelectFilter::make('status')->options(['pending' => 'Pending', 'queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped']),
            SelectFilter::make('channel')->options(['email' => 'Email']),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('retry')->label('Retry delivery')->color('warning')->requiresConfirmation()
                ->modalDescription('This requeues the same logical notification. SMTP cannot guarantee exactly-once delivery if a provider accepted a message before a connection failed.')
                ->visible(fn (NotificationDelivery $record): bool => $record->status === 'failed' && $record->channel === 'email')
                ->action(function (NotificationDelivery $record): void {
                    DB::transaction(function () use ($record): void {
                        $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($record->id);
                        if ($delivery->status !== 'failed') {
                            return;
                        }
                        $delivery->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]);
                        BookingAudit::query()->create([
                            'booking_id' => $delivery->booking_id,
                            'bundle_entitlement_id' => $delivery->bundle_entitlement_id,
                            'actor_id' => Auth::id(),
                            'subject_type' => 'notification_delivery',
                            'subject_id' => $delivery->id,
                            'action' => 'notification.delivery_retry_requested',
                            'metadata' => ['event' => $delivery->event, 'channel' => $delivery->channel, 'previous_attempts' => $record->attempts],
                        ]);
                    }, attempts: 3);
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
        return ['index' => ManageNotificationDeliveries::route('/')];
    }
}
