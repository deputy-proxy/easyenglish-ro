<?php

namespace App\Filament\Resources\Testimonials;

use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Models\Testimonial;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Testimonials';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('quote')->label('Testimonial')->required()->rows(5)->maxLength(10000)->columnSpanFull(),
            TextInput::make('author_name')->label('Approved display name')->maxLength(120)->helperText('Leave blank for anonymous attribution. Never enter private contact details.'),
            TextInput::make('author_context')->label('Approved learner context')->maxLength(160)->helperText('Optional, and only when approved for publication.'),
            Select::make('locale')->label('Language')->options(['ro' => 'Romanian', 'en' => 'English'])->default('ro')->required(),
            DateTimePicker::make('publication_consent_at')->label('Publication consent recorded at')->seconds(false)->required(fn (Get $get): bool => (bool) $get('is_published'))->helperText('Record the date and time when the learner explicitly approved publication. Required before publishing.'),
            Toggle::make('is_published')->label('Published')->default(false)->required(),
            TextInput::make('sort_order')->label('Display order')->numeric()->integer()->minValue(0)->default(0)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('quote')->label('Testimonial')->limit(75)->searchable(),
            TextColumn::make('author_name')->label('Attribution')->placeholder('Anonymous')->searchable(),
            TextColumn::make('locale')->label('Language')->badge()->formatStateUsing(fn (string $state): string => strtoupper($state)),
            IconColumn::make('is_published')->label('Published')->boolean(),
            TextColumn::make('publication_consent_at')->label('Consent recorded')->dateTime('d M Y H:i')->placeholder('Not recorded')->sortable(),
            TextColumn::make('sort_order')->label('Order')->sortable(),
            TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('locale')->options(['ro' => 'Romanian', 'en' => 'English']),
            SelectFilter::make('is_published')->label('Publication status')->options(['1' => 'Published', '0' => 'Unpublished']),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->isAdmin() ?? false)
                ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
        ])->toolbarActions([
            BulkActionGroup::make([DeleteBulkAction::make()
                ->authorize(fn (): bool => auth()->user()?->isAdmin() ?? false)
                ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)]),
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
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageTestimonials::route('/')];
    }
}
