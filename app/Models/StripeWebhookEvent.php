<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable|null $processed_at */
class StripeWebhookEvent extends Model
{
    protected $fillable = ['provider_event_id', 'event_type', 'payload_sha256', 'status', 'attempts', 'processed_at', 'last_error'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
