<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_reference
 * @property int|null $bundle_entitlement_id
 * @property int|null $free_session_claim_id
 * @property string $status
 * @property string $locale
 * @property string|null $credit_type
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $hold_expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expired_at
 * @property BundleEntitlement|null $entitlement
 * @property FreeSessionClaim|null $freeSessionClaim
 */
class Booking extends Model
{
    public function setCustomerPhoneAttribute(string $value): void
    {
        $this->attributes['customer_phone'] = PhoneNumber::normalize($value);
    }

    protected $fillable = [
        'public_reference', 'bundle_entitlement_id', 'free_session_claim_id', 'customer_name', 'customer_email',
        'customer_phone', 'english_level', 'session_goal', 'notes', 'accepted_policy_version', 'starts_at', 'ends_at',
        'duration_minutes', 'status', 'locale', 'credit_type', 'hold_expires_at', 'confirmed_at', 'cancelled_at', 'expired_at',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }

    /** @return BelongsTo<FreeSessionClaim, $this> */
    public function freeSessionClaim(): BelongsTo
    {
        return $this->belongsTo(FreeSessionClaim::class);
    }

    /** @return HasMany<BookingAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(BookingAudit::class);
    }
}
