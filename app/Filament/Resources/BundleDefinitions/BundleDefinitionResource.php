<?php

namespace App\Filament\Resources\BundleDefinitions;

use App\Filament\Resources\BundleDefinitions\Pages\ManageBundleDefinitions;
use App\Models\BundleDefinition;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
            Section::make('Bundle details')->schema([
                Hidden::make('name')->default(fn (): string => (string) Str::uuid())->required(),
                TextInput::make('name_ro')->label('Bundle name (Romanian)')->required()->maxLength(120),
                TextInput::make('name_en')->label('Bundle name (English)')->required()->maxLength(120),
                TextInput::make('short_description.ro')->label('Short description (Romanian)')->required()->maxLength(180),
                TextInput::make('short_description.en')->label('Short description (English)')->required()->maxLength(180),
                TextInput::make('tagline.ro')->label('Main tagline (Romanian)')->required()->maxLength(180),
                TextInput::make('tagline.en')->label('Main tagline (English)')->required()->maxLength(180),
                TextInput::make('paid_credits')->label('Number of paid sessions')->numeric()->integer()->minValue(1)->maxValue(500)->required(),
                TextInput::make('price_ron')->label('Regular price (RON)')->numeric()->integer()->minValue(1)->maxValue(1000000)->required()->live(),
                TextInput::make('sort_order')->label('Display order')->numeric()->integer()->minValue(0)->default(0)->required(),
                Toggle::make('is_active')->label('Available for purchase')->default(true)->required(),
                Toggle::make('featured')->label('Featured bundle')->helperText('Only one bundle can be featured at a time.'),
            ])->columns(2),
            Section::make('Features')->description('Displayed as a checklist on the public bundle card.')->schema([
                Builder::make('features')->label('Bundle features')->blocks([
                    Block::make('feature')->label('Feature')->schema([
                        TextInput::make('ro')->label('Romanian')->required()->maxLength(180),
                        TextInput::make('en')->label('English')->required()->maxLength(180),
                    ])->columns(2),
                ])->default([])->reorderable()->addActionLabel('Add feature')->columnSpanFull(),
            ]),
            Section::make('Discounts')->description('Discount periods cannot overlap across bundles. Only one discount can be active at a time. The discounted price is calculated from the regular price.')->schema([
                Builder::make('discounts')->label('Discount periods')->blocks([
                    Block::make('discount')->label('Discount')->schema([
                        TextInput::make('title.ro')->label('Title (Romanian)')->required()->maxLength(120),
                        TextInput::make('title.en')->label('Title (English)')->required()->maxLength(120),
                        DatePicker::make('start_date')->label('Start date')->native(false)->required(),
                        DatePicker::make('end_date')->label('End date')->native(false)->required()->afterOrEqual('start_date'),
                        TextInput::make('percentage')->label('Discount (%)')->numeric()->integer()->minValue(1)->maxValue(99)->required()->live(),
                        TextInput::make('new_price')->label('Calculated price (RON)')->numeric()->integer()->disabled()->dehydrated(false)->helperText('Calculated automatically from the regular price and discount percentage when saved.'),
                    ])->columns(2),
                ])->default([])->reorderable()->addActionLabel('Add discount period')->columnSpanFull(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ro')->label('Bundle')->searchable()->sortable(),
            TextColumn::make('paid_credits')->label('Paid sessions')->sortable(),
            TextColumn::make('price_ron')->label('Regular price')->suffix(' RON')->sortable(),
            IconColumn::make('featured')->label('Featured')->boolean(),
            TextColumn::make('sort_order')->label('Display order')->sortable(),
            IconColumn::make('is_active')->label('Available')->boolean(),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->isAdmin() ?? false)
                ->visible(fn (BundleDefinition $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->entitlements()->exists()),
        ]);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageBundleDefinitions::route('/')];
    }
}
