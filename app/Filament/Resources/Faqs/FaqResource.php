<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Models\Faq;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $navigationLabel = 'FAQs';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('answer')->required()->rows(6)->maxLength(20000)->columnSpanFull()
                ->helperText('Plain text only. Line breaks are preserved on the public website.'),
            TextInput::make('category')->maxLength(100),
            Select::make('locale')->options(['ro' => 'Romanian', 'en' => 'English'])->default('ro')->required(),
            Toggle::make('is_published')->label('Published')->default(false)->required(),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('question')->searchable()->limit(80),
            TextColumn::make('category')->placeholder('Uncategorised')->searchable(),
            TextColumn::make('locale')->badge()->formatStateUsing(fn (string $state): string => strtoupper($state)),
            IconColumn::make('is_published')->boolean(),
            TextColumn::make('sort_order')->sortable(),
            TextColumn::make('updated_at')->dateTime('d M Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('locale')->options(['ro' => 'Romanian', 'en' => 'English']),
            SelectFilter::make('category')->options(fn (): array => Faq::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            SelectFilter::make('is_published')->label('Publication status')->options(['1' => 'Published', '0' => 'Unpublished']),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([DeleteBulkAction::make()]),
        ]);
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return true;
    }

    public static function getPages(): array
    {
        return ['index' => ManageFaqs::route('/')];
    }
}
