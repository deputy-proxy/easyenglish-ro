<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\ManageTeachers;
use App\Jobs\Calendar\SyncTeacherCalendar;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TeacherResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Teachers';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_teacher', true)->with('teacherCalendar');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Teacher account')->schema([
                Hidden::make('is_teacher')->default(true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(254)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->label('Contact phone')->maxLength(32),
                TextInput::make('password')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
                Toggle::make('teacher_active')->label('Active for new assignments')->default(false)->required(),
            ])->columns(2),
            Section::make('Weekly availability')->description('Times are in Europe/Bucharest. Multiple intervals per day are supported.')->schema([
                Repeater::make('availabilityRules')->relationship()->schema([
                    Select::make('weekday')->label('Day')->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])->required()->native(false),
                    TimePicker::make('starts_at')->seconds(false)->required(),
                    TimePicker::make('ends_at')->seconds(false)->required(),
                    Toggle::make('is_active')->default(true),
                ])->columns(4)->defaultItems(0)->addActionLabel('Add working interval'),
            ]),
            Section::make('Date-specific exceptions')->schema([
                Repeater::make('availabilityExceptions')->relationship()->schema([
                    Select::make('kind')->options(['blocked' => 'Unavailable', 'availability_override' => 'Override weekly availability'])->required()->native(false),
                    DateTimePicker::make('starts_at')->timezone('Europe/Bucharest')->seconds(false)->required(),
                    DateTimePicker::make('ends_at')->timezone('Europe/Bucharest')->seconds(false)->required(),
                    Textarea::make('reason')->maxLength(500)->rows(2),
                ])->columns(2)->defaultItems(0)->addActionLabel('Add date exception'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone')->label('Contact phone'),
            IconColumn::make('teacher_active')->label('Active')->boolean(),
            TextColumn::make('teacherCalendar.calendar_email')->label('Calendar')->placeholder('—'),
            TextColumn::make('teacherCalendar.status')->label('Google Calendar')->badge()->placeholder('Not connected'),
            TextColumn::make('teacherCalendar.last_synced_at')->label('Last sync')->dateTime('d M Y H:i')->timezone('Europe/Bucharest')->placeholder('Never'),
            TextColumn::make('teacherCalendar.last_sync_error')->label('Calendar error')->limit(50)->placeholder('—'),
        ])->defaultSort('name')->recordActions([
            EditAction::make(),
            Action::make('retryCalendarSync')->label('Sync calendar now')->icon(Heroicon::OutlinedArrowPath)->visible(fn (User $record): bool => $record->teacherCalendar !== null)->action(fn (User $record) => SyncTeacherCalendar::dispatch($record->teacherCalendar->id)),
        ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeachers::route('/')];
    }
}
