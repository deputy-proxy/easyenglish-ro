<?php

namespace App\Filament\Resources\DiscountVouchers;

use App\Filament\Resources\DiscountVouchers\Pages\CreateDiscountVoucher;
use App\Filament\Resources\DiscountVouchers\Pages\EditDiscountVoucher;
use App\Filament\Resources\DiscountVouchers\Pages\ListDiscountVouchers;
use App\Filament\Resources\DiscountVouchers\Schemas\DiscountVoucherForm;
use App\Filament\Resources\DiscountVouchers\Tables\DiscountVouchersTable;
use App\Models\DiscountVoucher;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DiscountVoucherResource extends Resource
{
    protected static ?string $model = DiscountVoucher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Discount vouchers';

    protected static ?string $modelLabel = 'discount voucher';

    protected static ?string $pluralModelLabel = 'discount vouchers';

    protected static string|\UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return DiscountVoucherForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('bundle.localized_name')->label('Eligible bundle')->state(fn (DiscountVoucher $record): string => $record->bundle->localizedName()),
                TextEntry::make('discount_percentage')->suffix('%'),
                TextEntry::make('redemption_count')->label('Successful redemptions'),
                TextEntry::make('max_redemptions')->label('Maximum redemptions')->placeholder('Unlimited'),
            ])->columns(2),
            Section::make('Redemption history')->schema([
                TextEntry::make('redemption_history')->label('Customer / purchase / redemption time')->state(fn (DiscountVoucher $record): string => $record->redemptions()
                    ->with('entitlement')
                    ->where('status', 'redeemed')
                    ->latest('redeemed_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($redemption): string => $redemption->entitlement->customer_name.' <'.$redemption->entitlement->customer_email.'> | '.$redemption->entitlement->paid_amount_ron.' RON | '.($redemption->redeemed_at?->timezone('Europe/Bucharest')->format('Y-m-d H:i') ?? 'Unknown date'))
                    ->implode("\n") ?: 'No successful redemptions yet')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return DiscountVouchersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->isAdmin() ?? false)
            && $record instanceof DiscountVoucher
            && ! $record->redemptions()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscountVouchers::route('/'),
            'create' => CreateDiscountVoucher::route('/create'),
            'edit' => EditDiscountVoucher::route('/{record}/edit'),
        ];
    }
}
