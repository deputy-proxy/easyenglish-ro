<?php

namespace App\Models;

use Database\Factories\TestimonialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $quote
 * @property string|null $author_name
 * @property string|null $author_context
 * @property string $locale
 * @property Carbon|null $publication_consent_at
 * @property bool $is_published
 * @property int $sort_order
 */
class Testimonial extends Model
{
    /** @use HasFactory<TestimonialFactory> */
    use HasFactory;

    protected $fillable = [
        'quote',
        'author_name',
        'author_context',
        'locale',
        'publication_consent_at',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'publication_consent_at' => 'immutable_datetime',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Testimonial $testimonial): void {
            if ($testimonial->is_published && $testimonial->publication_consent_at === null) {
                throw ValidationException::withMessages([
                    'publication_consent_at' => 'Publication consent must be recorded before a testimonial can be published.',
                ]);
            }
        });
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereNotNull('publication_consent_at');
    }
}
