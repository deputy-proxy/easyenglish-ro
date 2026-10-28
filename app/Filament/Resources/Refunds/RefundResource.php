<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Models\PaymentRefund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RefundResource extends Resource
{
    protected static ?string $model = PaymentRefund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $navigationLabel = 'Refunds';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Refund #')->sortable(),
            TextColumn::make('payment.entitlement.customer_name')->label('Customer')->searchable(),
            TextColumn::make('payment.entitlement.customer_email')->label('Email')->searchable(),
            TextColumn::make('payment_id')->label('Payment #')->sortable(),
            TextColumn::make('amount_ron')->label('Amount')->formatStateUsing(fn (int $state): string => number_format($state, 2).' RON')->sortable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'confirmed', 'succeeded', 'processed' => 'success', 'pending' => 'warning', 'failed', 'cancelled' => 'danger', default => 'gray'
            }),
            TextColumn::make('reason')->limit(70)->tooltip(fn (PaymentRefund $record): string => $record->reason),
            TextColumn::make('actor.name')->label('Processed by')->placeholder('System'),
            TextColumn::make('confirmed_at')->dateTime('d M Y H:i')->placeholder('Not confirmed'),
            TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'confirmed' => 'Confirmed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'])])->defaultSort('created_at', 'desc');
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
        return ['index' => ManageRefunds::route('/')];
    }
}
