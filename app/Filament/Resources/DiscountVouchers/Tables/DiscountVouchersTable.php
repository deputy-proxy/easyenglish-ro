<?php

namespace App\Filament\Resources\DiscountVouchers\Tables;

use App\Models\DiscountVoucher;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DiscountVouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->label('Campaign')->searchable()->sortable(),
                TextColumn::make('bundle.name_ro')->label('Bundle')->searchable(),
                TextColumn::make('discount_percentage')->label('Discount')->suffix('%')->sortable(),
                TextColumn::make('redemption_count')->label('Used')->sortable(),
                TextColumn::make('redemptions_count')->counts('redemptions')->label('Redemption records'),
                TextColumn::make('max_redemptions')->label('Limit')->placeholder('Unlimited'),
                TextColumn::make('starts_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->placeholder('Immediately')->sortable(),
                TextColumn::make('expires_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->placeholder('No expiry')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active status'),
                SelectFilter::make('bundle_definition_id')->label('Bundle')->relationship('bundle', 'name_ro'),
                SelectFilter::make('validity')->options([
                    'upcoming' => 'Not started',
                    'valid' => 'Currently valid',
                    'expired' => 'Expired',
                ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'upcoming' => $query->whereNotNull('starts_at')->where('starts_at', '>', now()),
                    'valid' => $query->where(fn (Builder $valid): Builder => $valid->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                        ->where(fn (Builder $valid): Builder => $valid->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                    'expired' => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                    default => $query,
                }),
                SelectFilter::make('redemption_usage')->options([
                    'unused' => 'Unused',
                    'used' => 'Used',
                    'exhausted' => 'Limit reached',
                ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'unused' => $query->where('redemption_count', 0),
                    'used' => $query->where('redemption_count', '>', 0),
                    'exhausted' => $query->whereNotNull('max_redemptions')->whereColumn('redemption_count', '>=', 'max_redemptions'),
                    default => $query,
                }),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (DiscountVoucher $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->color(fn (DiscountVoucher $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (DiscountVoucher $record) => $record->update(['is_active' => ! $record->is_active])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorize(fn (): bool => auth()->user()?->isAdmin() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
