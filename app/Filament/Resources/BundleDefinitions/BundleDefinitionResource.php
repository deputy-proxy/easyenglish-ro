<?php

namespace App\Filament\Resources\BundleDefinitions;

use App\Filament\Resources\BundleDefinitions\Pages\ManageBundleDefinitions;
use App\Models\BundleDefinition;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundleDefinitionResource extends Resource
{
    protected static ?string $model = BundleDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Lesson bundles';

    protected static ?string $modelLabel = 'lesson bundle';

    protected static ?string $pluralModelLabel = 'lesson bundles';

    protected static string|\UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Bundle name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('paid_credits')->label('Number of sessions')->numeric()->integer()->minValue(1)->maxValue(500)->required(),
            TextInput::make('price_ron')->label('Total price (RON)')->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
            TextInput::make('sort_order')->label('Display order')->numeric()->integer()->minValue(0)->default(0)->required(),
            Toggle::make('is_active')->label('Available for purchase')->default(true)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Bundle')->searchable()->sortable(),
            TextColumn::make('paid_credits')->label('Sessions')->sortable(),
            TextColumn::make('price_ron')->label('Total price')->suffix(' RON')->sortable(),
            TextColumn::make('sort_order')->label('Display order')->sortable(),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            DeleteAction::make()->visible(fn (BundleDefinition $record): bool => ! $record->entitlements()->exists()),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBundleDefinitions::route('/')];
    }
}
