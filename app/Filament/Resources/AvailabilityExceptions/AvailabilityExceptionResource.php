<?php

namespace App\Filament\Resources\AvailabilityExceptions;

use App\Filament\Resources\AvailabilityExceptions\Pages\ManageAvailabilityExceptions;
use App\Models\AvailabilityException;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AvailabilityExceptionResource extends Resource
{
    protected static ?string $model = AvailabilityException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Date exceptions';

    protected static ?string $modelLabel = 'availability exception';

    protected static ?string $pluralModelLabel = 'date exceptions';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->options(['availability_override' => 'Add availability', 'blocked' => 'Block time'])->required()->native(false),
            DateTimePicker::make('starts_at')->label('Starts (Europe/Bucharest)')->timezone('Europe/Bucharest')->seconds(false)->required(),
            DateTimePicker::make('ends_at')->label('Ends (Europe/Bucharest)')->timezone('Europe/Bucharest')->seconds(false)->after('starts_at')->required(),
            TextInput::make('reason')->maxLength(500)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => $state === 'blocked' ? 'Blocked' : 'Added availability'),
            TextColumn::make('starts_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->sortable(),
            TextColumn::make('ends_at')->dateTime('d M Y H:i')->timezone('Europe/Bucharest'),
            TextColumn::make('reason')->limit(50),
            TextColumn::make('creator.name')->label('Created by'),
        ])->defaultSort('starts_at', 'desc')->recordActions([
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
        return ['index' => ManageAvailabilityExceptions::route('/')];
    }
}
