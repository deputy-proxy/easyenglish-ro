<?php

namespace App\Filament\Resources\BundleEntitlements;

use App\Filament\Resources\BundleEntitlements\Pages\ManageBundleEntitlements;
use App\Models\BundleEntitlement;
use App\Services\Booking\BundleEntitlementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
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

class BundleEntitlementResource extends Resource
{
    protected static ?string $model = BundleEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Bundle entitlements';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer and bundle')->schema([
                TextEntry::make('customer_name'), TextEntry::make('customer_email'), TextEntry::make('customer_phone'),
                TextEntry::make('definition.name')->label('Bundle'), TextEntry::make('paid_amount_ron')->suffix(' RON'),
                TextEntry::make('status')->badge(), TextEntry::make('credits_remaining'), TextEntry::make('paid_credits'),
                TextEntry::make('free_credits_granted'), TextEntry::make('activated_at')->dateTime(), TextEntry::make('expires_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest'),
            ])->columns(2),
            Section::make('Credit ledger')->schema([
                TextEntry::make('transactions_count')->state(fn (BundleEntitlement $record): int => $record->transactions()->count()),
                TextEntry::make('payments_count')->state(fn (BundleEntitlement $record): int => $record->payments()->count()),
                TextEntry::make('recent_credit_ledger')->label('Recent credit ledger')->state(fn (BundleEntitlement $record): string => $record->transactions()->latest()->limit(10)->get()->map(fn ($transaction): string => $transaction->created_at->format('Y-m-d H:i').' | '.$transaction->type.' '.$transaction->credit_type.' '.$transaction->quantity.' | balance '.$transaction->balance_after.' | '.($transaction->reason ?? 'No reason'))->implode("\n") ?: 'No ledger entries')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('customer_name')->searchable(), TextColumn::make('customer_email')->searchable(),
            TextColumn::make('definition.name')->label('Bundle'), TextColumn::make('paid_amount_ron')->suffix(' RON')->sortable(),
            TextColumn::make('status')->badge(), TextColumn::make('credits_remaining')->label('Credits left'),
            TextColumn::make('expires_at')->dateTime('d M Y')->timezone('Europe/Bucharest')->sortable(),
        ])->filters([SelectFilter::make('status')->options(['pending_payment' => 'Pending payment', 'active' => 'Active', 'expired' => 'Expired', 'refunded' => 'Refunded'])])->defaultSort('created_at', 'desc')->recordActions([
            ViewAction::make(),
            Action::make('adjustPaidCredits')->label('Adjust paid credits')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('warning')->visible(fn (BundleEntitlement $record): bool => $record->status === 'active')->schema([
                TextInput::make('quantity')->label('Credit adjustment (+/-)')->integer()->required(),
                TextInput::make('reason')->label('Reason for audit log')->minLength(10)->maxLength(500)->required(),
            ])->requiresConfirmation()->action(function (BundleEntitlement $record, array $data): void {
                app(BundleEntitlementService::class)->adjustPaidCredits($record->id, (int) $data['quantity'], (int) Auth::id(), $data['reason']);
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
        return ['index' => ManageBundleEntitlements::route('/')];
    }
}
