<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider_message_id
 * @property string $channel
 * @property string $direction
 * @property string $from_phone
 * @property string|null $to_phone
 * @property string $type
 * @property string|null $body
 * @property string|null $provider_timestamp
 * @property CarbonImmutable|null $received_at
 * @property array<string, mixed>|null $context
 */
class Message extends Model
{
    protected $fillable = [
        'provider_message_id',
        'channel',
        'direction',
        'from_phone',
        'to_phone',
        'type',
        'body',
        'provider_timestamp',
        'received_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }
}
