<?php

namespace App\Filament\Resources\Enquiries;

use App\Filament\Resources\Enquiries\Pages\ManageEnquiries;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Models\Enquiry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?string $modelLabel = 'enquiry';

    protected static ?string $pluralModelLabel = 'enquiries';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact details')->schema([
                TextEntry::make('name'), TextEntry::make('email')->copyable(), TextEntry::make('subject'),
                TextEntry::make('created_at')->dateTime('d M Y H:i'), TextEntry::make('status')->badge(),
            ]),
            Section::make('Message')->schema([TextEntry::make('message')->columnSpanFull()->prose()]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->copyable(),
            TextColumn::make('subject')->searchable()->limit(60),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                Enquiry::STATUS_NEW => 'warning', Enquiry::STATUS_IN_PROGRESS => 'info', Enquiry::STATUS_RESOLVED => 'success', default => 'gray',
            }),
            TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->options(Enquiry::statusOptions())])->defaultSort('created_at', 'desc')->recordActions([
            ViewAction::make()->url(fn (Enquiry $record): string => static::getUrl('view', ['record' => $record])),
            Action::make('updateStatus')->label('Update status')->icon(Heroicon::OutlinedPencilSquare)
                ->schema([Select::make('status')->options(Enquiry::statusOptions())->required()])
                ->fillForm(fn (Enquiry $record): array => ['status' => $record->status])
                ->action(fn (Enquiry $record, array $data) => $record->update(['status' => $data['status']])),
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_staff === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->is_staff === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->is_staff === true;
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
        return [
            'index' => ManageEnquiries::route('/'),
            'view' => ViewEnquiry::route('/{record}'),
        ];
    }
}
