<?php

namespace App\Filament\Resources\DiscountVouchers\Schemas;

use App\Models\BundleDefinition;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DiscountVoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign voucher')->schema([
                TextInput::make('code')->label('Voucher code')->required()->minLength(2)->maxLength(64)->unique(ignoreRecord: true)->helperText('Stored in uppercase; lookup is case-insensitive.'),
                TextInput::make('name')->label('Campaign name')->required()->maxLength(120),
                Textarea::make('description')->rows(3)->maxLength(2000)->columnSpanFull(),
                Select::make('bundle_definition_id')->label('Eligible bundle')->options(fn (): array => BundleDefinition::query()->orderBy('sort_order')->get()->mapWithKeys(fn (BundleDefinition $bundle): array => [$bundle->id => $bundle->localizedName().' ('.$bundle->price_ron.' RON)'])->all())->searchable()->required(),
                TextInput::make('discount_percentage')->label('Discount (%)')->numeric()->integer()->minValue(1)->maxValue(99)->required(),
            ])->columns(2),
            Section::make('Validity and usage')->schema([
                DateTimePicker::make('starts_at')->label('Starts at')->timezone('Europe/Bucharest'),
                DateTimePicker::make('expires_at')->label('Expires at')->timezone('Europe/Bucharest')->after('starts_at'),
                TextInput::make('max_redemptions')->label('Maximum redemptions')->numeric()->integer()->minValue(1)->helperText('Leave empty for unlimited usage.'),
                Toggle::make('is_active')->label('Active')->default(true)->required(),
            ])->columns(2),
        ]);
    }
}
