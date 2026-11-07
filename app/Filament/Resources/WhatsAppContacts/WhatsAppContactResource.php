<?php

namespace App\Filament\Resources\WhatsAppContacts;

use App\Filament\Resources\WhatsAppContacts\Pages\ManageWhatsAppContacts;
use App\Models\WhatsAppContact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class WhatsAppContactResource extends Resource
{
    protected static ?string $model = WhatsAppContact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'WhatsApp contacts';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('phone')
                ->label('Phone number')
                ->searchable()
                ->copyable()
                ->sortable(),
            TextColumn::make('consent_status')
                ->label('Messaging consent')
                ->state(fn (WhatsAppContact $record): string => match (true) {
                    $record->consented_at !== null && $record->opted_out_at === null => 'Consented',
                    $record->opted_out_at !== null => 'Opted out',
                    default => 'No active consent',
                })
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Consented' => 'success',
                    'Opted out' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('consented_at')
                ->label('Consented')
                ->dateTime('d M Y H:i:s')
                ->sortable()
                ->placeholder('Not recorded'),
            TextColumn::make('consent_source')
                ->label('Consent source')
                ->badge()
                ->placeholder('Unknown'),
            TextColumn::make('opted_out_at')
                ->label('Opted out')
                ->dateTime('d M Y H:i:s')
                ->sortable()
                ->placeholder('No'),
            TextColumn::make('opt_out_source')
                ->label('Opt-out source')
                ->badge()
                ->placeholder('Not applicable'),
            TextColumn::make('updated_at')
                ->label('Last updated')
                ->dateTime('d M Y H:i:s')
                ->sortable(),
        ])->filters([
            SelectFilter::make('messaging_consent')
                ->label('Messaging consent')
                ->options([
                    'consented' => 'Consented',
                    'opted_out' => 'Opted out',
                    'none' => 'No active consent',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'consented' => $query->whereNotNull('consented_at')->whereNull('opted_out_at'),
                        'opted_out' => $query->whereNotNull('opted_out_at'),
                        'none' => $query->whereNull('consented_at')->whereNull('opted_out_at'),
                        default => $query,
                    };
                }),
        ])->defaultSort('updated_at', 'desc')->recordUrl(null);
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageWhatsAppContacts::route('/')];
    }
}
