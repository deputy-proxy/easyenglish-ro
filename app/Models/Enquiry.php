<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id @property string $name @property string $email @property string $subject @property string $message @property string $status */
class Enquiry extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = ['name', 'email', 'subject', 'message', 'status'];

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [self::STATUS_NEW => 'New', self::STATUS_IN_PROGRESS => 'In progress', self::STATUS_RESOLVED => 'Resolved'];
    }
}
