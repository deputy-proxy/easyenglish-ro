<?php

namespace App\Filament\Resources\CallEvents;

use App\Filament\Resources\CallEvents\Pages\ManageCallEvents;
use App\Jobs\CallEvents\CancelBookingCallEvent;
use App\Jobs\CallEvents\CreateBookingCallEvent;
use App\Jobs\CallEvents\RetrieveBookingCallEvent;
use App\Models\BookingAudit;
use App\Models\BookingCallEvent;
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

class CallEventResource extends Resource
{
    protected static ?string $model = BookingCallEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Call events';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['booking', 'teacher']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i:s')->sortable(),
            TextColumn::make('booking.public_reference')->label('Booking')->searchable(),
            TextColumn::make('teacher.name')->label('Assigned teacher')->placeholder('Unassigned'),
            TextColumn::make('revision')->numeric()->sortable(),
            TextColumn::make('scheduled_start_at')->label('Starts (Bucharest)')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->sortable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'scheduled', 'cancelled' => 'success',
                'failed' => 'danger',
                'pending', 'queued', 'creating', 'reconciliation_pending', 'reconciling', 'cancel_pending', 'cancelling' => 'warning',
                default => 'gray',
            })->sortable(),
            TextColumn::make('attempts')->numeric()->sortable(),
            TextColumn::make('next_attempt_at')->dateTime('d M H:i:s')->placeholder('Not scheduled'),
            TextColumn::make('last_error_category')->label('Error')->badge()->placeholder('None'),
            TextColumn::make('last_error_message')->label('Last error')->limit(70)->placeholder('None'),
        ])->filters([
            SelectFilter::make('status')->options([
                'pending' => 'Pending', 'queued' => 'Queued', 'creating' => 'Creating', 'reconciliation_pending' => 'Reconciliation pending',
                'reconciling' => 'Reconciling', 'scheduled' => 'Scheduled', 'cancel_pending' => 'Cancellation pending',
                'cancelling' => 'Cancelling', 'cancelled' => 'Cancelled', 'failed' => 'Failed',
            ]),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('retry')
                ->label('Retry call setup')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (BookingCallEvent $record): bool => $record->status === 'failed' && (bool) config('services.openwa.enabled'))
                ->action(function (BookingCallEvent $record): void {
                    $operation = DB::transaction(function () use ($record): string {
                        $event = BookingCallEvent::query()->lockForUpdate()->findOrFail($record->id);
                        if ($event->status !== 'failed') {
                            return 'none';
                        }

                        $operation = $event->cancel_requested_at !== null
                            ? 'cancel'
                            : ($event->provider_event_id !== null ? 'retrieve' : 'create');
                        $event->update([
                            'status' => match ($operation) {
                                'cancel' => 'cancel_pending',
                                'retrieve' => 'reconciliation_pending',
                                default => 'pending',
                            },
                            'attempts' => 0,
                            'next_attempt_at' => now(),
                            'last_error_category' => null,
                            'last_error_message' => null,
                        ]);
                        BookingAudit::query()->create([
                            'booking_id' => $event->booking_id,
                            'actor_id' => Auth::id(),
                            'subject_type' => 'booking_call_event',
                            'subject_id' => $event->id,
                            'action' => 'call_event.retry_requested',
                            'metadata' => ['revision' => $event->revision, 'operation' => $operation],
                        ]);

                        return $operation;
                    }, attempts: 3);

                    match ($operation) {
                        'cancel' => CancelBookingCallEvent::dispatch($record->id),
                        'retrieve' => RetrieveBookingCallEvent::dispatch($record->id),
                        'create' => CreateBookingCallEvent::dispatch($record->booking_id, $record->revision),
                        default => null,
                    };
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
        return ['index' => ManageCallEvents::route('/')];
    }
}
