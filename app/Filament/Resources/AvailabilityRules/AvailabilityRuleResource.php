<?php

namespace App\Filament\Resources\AvailabilityRules;

use App\Filament\Resources\AvailabilityRules\Pages\ManageAvailabilityRules;
use App\Models\AvailabilityRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AvailabilityRuleResource extends Resource
{
    protected static ?string $model = AvailabilityRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Weekly availability';

    protected static ?string $modelLabel = 'availability rule';

    protected static ?string $pluralModelLabel = 'weekly availability';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('user_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('weekday')->label('Day of week')->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])->required()->native(false),
            TimePicker::make('starts_at')->label('Window starts (Bucharest time)')->seconds(false)->required(),
            TimePicker::make('ends_at')->label('Window ends (Bucharest time)')->seconds(false)->required(),
            Toggle::make('is_active')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('weekday')->formatStateUsing(fn ($state): string => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][(int) $state] ?? 'Unknown')->sortable(),
            TextColumn::make('starts_at')->label('Starts')->time('H:i'),
            TextColumn::make('ends_at')->label('Ends')->time('H:i'),
            IconColumn::make('is_active')->boolean(),
        ])->defaultSort('weekday')->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAvailabilityRules::route('/')];
    }
}
