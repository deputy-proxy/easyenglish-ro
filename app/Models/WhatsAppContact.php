<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property int $id @property string $phone @property CarbonImmutable|null $consented_at @property CarbonImmutable|null $opted_out_at @property string|null $consent_source @property string|null $opt_out_source */
class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    protected $fillable = ['phone', 'consented_at', 'opted_out_at', 'consent_source', 'opt_out_source'];

    protected function casts(): array
    {
        return ['consented_at' => 'immutable_datetime', 'opted_out_at' => 'immutable_datetime'];
    }

    public static function recordConsent(string $phone, string $source): self
    {
        $normalized = PhoneNumber::normalize($phone);

        return self::query()->updateOrCreate(
            ['phone' => $normalized],
            ['consented_at' => now(), 'opted_out_at' => null, 'consent_source' => $source, 'opt_out_source' => null],
        );
    }

    public static function recordOptOut(string $phone, string $source): void
    {
        $normalized = PhoneNumber::normalize($phone);
        self::query()->updateOrCreate(
            ['phone' => $normalized],
            ['opted_out_at' => now(), 'opt_out_source' => $source],
        );
    }

    public function canReceiveServiceMessages(): bool
    {
        return $this->consented_at !== null && $this->opted_out_at === null;
    }
}
