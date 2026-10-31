<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Users';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account details')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(254)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->maxLength(32),
                TextInput::make('password')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
            ])->columns(2),
            Section::make('Account roles')->schema([
                Toggle::make('is_staff')->label('Staff access')->default(false),
                Toggle::make('is_teacher')->label('Teacher account')->default(false),
                Toggle::make('teacher_active')->label('Active for new teaching assignments')->default(false),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone')->placeholder('—'),
            IconColumn::make('is_staff')->label('Staff')->boolean(),
            IconColumn::make('is_teacher')->label('Teacher')->boolean(),
            IconColumn::make('teacher_active')->label('Taking assignments')->boolean(),
            TextColumn::make('created_at')->label('Created')->dateTime('d M Y')->sortable(),
        ])->filters([
            SelectFilter::make('account_type')->label('Account type')->options([
                'staff' => 'Staff',
                'teacher' => 'Teachers',
                'customer' => 'Customers',
            ])->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                'staff' => $query->where('is_staff', true),
                'teacher' => $query->where('is_teacher', true),
                'customer' => $query->where('is_staff', false)->where('is_teacher', false),
                default => $query,
            }),
        ])->defaultSort('created_at', 'desc')->recordActions([EditAction::make()]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
