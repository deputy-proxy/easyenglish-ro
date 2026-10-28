<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payments';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Payment #')->sortable(),
            TextColumn::make('entitlement.customer_name')->label('Customer')->searchable(),
            TextColumn::make('entitlement.customer_email')->label('Email')->searchable(),
            TextColumn::make('provider')->badge(),
            TextColumn::make('amount_ron')->label('Amount')->formatStateUsing(fn (int $state): string => number_format($state, 2).' RON')->sortable(),
            TextColumn::make('refunded_amount_ron')->label('Refunded')->formatStateUsing(fn (int $state): string => number_format($state, 2).' RON'),
            TextColumn::make('currency')->toggleable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'confirmed', 'succeeded', 'paid' => 'success', 'pending' => 'warning', 'failed', 'cancelled' => 'danger', default => 'gray'
            }),
            TextColumn::make('provider_payment_id')->label('Provider payment ID')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('confirmed_at')->dateTime('d M Y H:i')->sortable()->placeholder('Not confirmed'),
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
        return ['index' => ManagePayments::route('/')];
    }
}
