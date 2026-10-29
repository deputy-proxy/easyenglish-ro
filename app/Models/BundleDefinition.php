<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $price_ron
 * @property int $paid_credits
 * @property int $included_free_credits
 * @property int $sort_order
 */
class BundleDefinition extends Model
{
    protected $fillable = ['name', 'price_ron', 'paid_credits', 'included_free_credits', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<BundleEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(BundleEntitlement::class);
    }
}
