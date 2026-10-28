<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Customers';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_staff', false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer profile')->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('phone')->placeholder('Not provided'),
                TextEntry::make('english_level')->label('English level')->placeholder('Not provided'),
                TextEntry::make('session_goal')->label('Learning goal')->placeholder('Not provided'),
                TextEntry::make('session_notes')->label('Notes')->placeholder('None'),
                TextEntry::make('email_verified_at')->label('Email verified')->dateTime(),
                TextEntry::make('phone_verified_at')->label('Phone verified')->dateTime(),
                TextEntry::make('created_at')->label('Registered')->dateTime(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone')->searchable()->placeholder('—'),
            TextColumn::make('english_level')->label('Level')->badge()->placeholder('—'),
            TextColumn::make('email_verified_at')->label('Email verified')->dateTime('d M Y')->placeholder('No'),
            TextColumn::make('created_at')->label('Registered')->dateTime('d M Y')->sortable(),
        ])->filters([
            SelectFilter::make('email_verified_at')->label('Email verification')->options(['verified' => 'Verified', 'unverified' => 'Unverified'])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'verified' => $query->whereNotNull('email_verified_at'),
                'unverified' => $query->whereNull('email_verified_at'),
                default => $query,
            }),
        ])->defaultSort('created_at', 'desc')->recordActions([ViewAction::make()]);
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
        return ['index' => ManageCustomers::route('/')];
    }
}
