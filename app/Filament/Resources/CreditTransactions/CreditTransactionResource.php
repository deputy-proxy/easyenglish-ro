<?php

namespace App\Filament\Resources\CreditTransactions;

use App\Filament\Resources\CreditTransactions\Pages\ManageCreditTransactions;
use App\Models\CreditTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CreditTransactionResource extends Resource
{
    protected static ?string $model = CreditTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Credit ledger';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('entitlement.customer_email')->label('Customer')->searchable(),
            TextColumn::make('booking.public_reference')->label('Booking')->searchable()->placeholder('Bundle-level change'),
            TextColumn::make('type')->badge(),
            TextColumn::make('credit_type')->badge(),
            TextColumn::make('quantity')->sortable(),
            TextColumn::make('balance_after')->label('Balance after'),
            TextColumn::make('reason')->limit(60),
            TextColumn::make('creator.name')->label('Staff actor')->placeholder('System'),
        ])->filters([
            SelectFilter::make('type')->options(['granted' => 'Granted', 'reserved' => 'Reserved', 'consumed' => 'Consumed', 'restored' => 'Restored', 'adjusted' => 'Adjusted', 'forfeited' => 'Forfeited', 'expired' => 'Expired', 'refunded' => 'Refunded']),
            SelectFilter::make('credit_type')->options(['paid' => 'Paid', 'free' => 'Included free']),
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
        return ['index' => ManageCreditTransactions::route('/')];
    }
}
