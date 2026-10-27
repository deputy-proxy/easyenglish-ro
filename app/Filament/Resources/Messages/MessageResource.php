<?php

namespace App\\Filament\\Resources\\Messages;

use App\\Filament\\Resources\\Messages\\Pages\\ManageMessages;
use App\\Models\\Message;
use BackedEnum;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Schema;
use Filament\\Support\\Icons\\Heroicon;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Filters\\SelectFilter;
use Filament\\Tables\\Table;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Database\\Eloquent\\Model;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'WhatsApp messages';

    protected static string|\\UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('channel', 'whatsapp')->where('direction', 'inbound');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('received_at')->label('Received')->dateTime('d M Y H:i:s')->sortable()->placeholder('Unknown'),
            TextColumn::make('from_phone')->label('From')->searchable()->copyable(),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('body')->label('Message')->wrap()->searchable()->placeholder('No text content'),
            TextColumn::make('provider_message_id')->label('WhatsApp message ID')->limit(24)->copyable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('type')->options([
                'text' => 'Text',
                'image' => 'Image',
                'audio' => 'Audio',
                'video' => 'Video',
                'document' => 'Document',
                'sticker' => 'Sticker',
                'location' => 'Location',
                'interactive' => 'Interactive',
                'button' => 'Button',
                'unknown' => 'Other',
            ]),
        ])->defaultSort('received_at', 'desc')->recordUrl(null);
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

    public static function getPages(): array
    {
        return ['index' => ManageMessages::route('/')];
    }
}
