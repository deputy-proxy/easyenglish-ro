<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id @property string $customer_email @property string $customer_phone @property string $locale @property string $return_to @property int|null $return_bundle_id @property string $email_token_hash @property string|null $email_code_hash @property int $email_attempts @property string $phone_code_hash @property CarbonImmutable|null $email_verified_at @property CarbonImmutable|null $phone_verified_at @property CarbonImmutable $expires_at @property CarbonImmutable|null $email_sent_at @property CarbonImmutable|null $phone_sent_at @property int $phone_attempts @property CarbonImmutable|null $consumed_at */
class ContactVerificationChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'customer_email', 'customer_phone', 'locale', 'return_to', 'return_bundle_id', 'email_token_hash', 'email_code_hash', 'phone_code_hash',
        'email_verified_at', 'phone_verified_at', 'expires_at', 'email_sent_at', 'phone_sent_at',
        'phone_attempts', 'email_attempts', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'email_sent_at' => 'immutable_datetime',
            'phone_sent_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'phone_attempts' => 'integer',
            'email_attempts' => 'integer',
        ];
    }

    public function setCustomerPhoneAttribute(string $value): void
    {
        $this->attributes['customer_phone'] = PhoneNumber::normalize($value);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && CarbonImmutable::parse((string) $this->getRawOriginal('expires_at'))->isFuture();
    }

    public function isComplete(): bool
    {
        if (config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only') {
            return $this->email_verified_at !== null;
        }

        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }
}
