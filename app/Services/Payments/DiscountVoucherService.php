<?php

namespace App\Services\Payments;

use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\DiscountVoucher;
use App\Models\DiscountVoucherRedemption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscountVoucherService
{
    /**
     * @return array{
     *     original_price_ron:int,
     *     current_price_ron:int,
     *     savings_ron:int,
     *     discount_type:string,
     *     percentage:int,
     *     discount:array<string, mixed>
     * }
     */
    public function quote(BundleDefinition $bundle, string $code, ?CarbonImmutable $at = null, ?BundleEntitlement $existingEntitlement = null): array
    {
        $normalizedCode = self::normalizeCode($code);
        $voucher = DiscountVoucher::query()->forCode($normalizedCode)->first();

        if ($voucher === null) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_invalid')]);
        }

        $at ??= CarbonImmutable::now();
        if (! $voucher->is_active) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_inactive')]);
        }
        if ($voucher->starts_at !== null && $voucher->starts_at->greaterThan($at)) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_not_started')]);
        }
        if ($voucher->expires_at !== null && $voucher->expires_at->lessThanOrEqualTo($at)) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_expired')]);
        }
        if ($voucher->bundle_definition_id !== $bundle->id) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_wrong_bundle')]);
        }
        if ($voucher->max_redemptions !== null && $this->occupiedRedemptionSlots($voucher, $existingEntitlement) >= $voucher->max_redemptions) {
            throw ValidationException::withMessages(['voucher' => __('public.voucher_exhausted')]);
        }

        $originalPrice = (int) $bundle->price_ron;
        $currentPrice = (int) round($originalPrice * (100 - $voucher->discount_percentage) / 100);
        $currentPrice = max(1, min($originalPrice - 1, $currentPrice));
        $savings = $originalPrice - $currentPrice;

        return [
            'original_price_ron' => $originalPrice,
            'current_price_ron' => $currentPrice,
            'savings_ron' => $savings,
            'discount_type' => 'voucher',
            'percentage' => $voucher->discount_percentage,
            'discount' => [
                'discount_type' => 'voucher',
                'voucher_code' => $voucher->code,
                'voucher_name' => $voucher->name,
                'percentage' => $voucher->discount_percentage,
                'discount_amount_ron' => $savings,
            ],
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function reserveForEntitlement(string $code, BundleDefinition $bundle, BundleEntitlement $entitlement): void
    {
        DB::transaction(function () use ($code, $bundle, $entitlement): void {
            $voucher = DiscountVoucher::query()
                ->where('code', self::normalizeCode($code))
                ->lockForUpdate()
                ->first();

            if ($voucher === null || ! $voucher->isValidFor($bundle)) {
                throw ValidationException::withMessages(['voucher' => __('public.voucher_unavailable_checkout')]);
            }

            $redemption = DiscountVoucherRedemption::query()
                ->where('bundle_entitlement_id', $entitlement->id)
                ->lockForUpdate()
                ->first();

            if (in_array($redemption?->status, ['reserved', 'redeemed'], true)) {
                return;
            }

            $occupied = $voucher->redemptions()->whereIn('status', ['reserved', 'redeemed'])->count();
            if ($voucher->max_redemptions !== null && $occupied >= $voucher->max_redemptions) {
                throw ValidationException::withMessages(['voucher' => __('public.voucher_exhausted')]);
            }

            if ($redemption === null) {
                DiscountVoucherRedemption::query()->create([
                    'discount_voucher_id' => $voucher->id,
                    'bundle_entitlement_id' => $entitlement->id,
                    'status' => 'reserved',
                ]);
            } elseif ($redemption->discount_voucher_id !== $voucher->id) {
                throw ValidationException::withMessages(['voucher' => __('public.voucher_purchase_locked')]);
            } else {
                $redemption->update(['status' => 'reserved', 'redeemed_at' => null]);
            }
        }, attempts: 3);
    }

    public function redeemForEntitlement(BundleEntitlement $entitlement): void
    {
        if (! is_string($entitlement->voucher_code_snapshot) || $entitlement->voucher_code_snapshot === '') {
            return;
        }

        DB::transaction(function () use ($entitlement): void {
            $redemption = DiscountVoucherRedemption::query()
                ->where('bundle_entitlement_id', $entitlement->id)
                ->lockForUpdate()
                ->first();

            if ($redemption === null) {
                throw ValidationException::withMessages(['voucher' => __('public.voucher_unavailable_checkout')]);
            }

            if ($redemption->status === 'redeemed') {
                return;
            }

            $voucher = DiscountVoucher::query()->lockForUpdate()->findOrFail($redemption->discount_voucher_id);
            $redemption->update(['status' => 'redeemed', 'redeemed_at' => now()]);
            $voucher->increment('redemption_count');
        }, attempts: 3);
    }

    public function releaseForEntitlement(BundleEntitlement $entitlement): void
    {
        if (! is_string($entitlement->voucher_code_snapshot) || $entitlement->voucher_code_snapshot === '') {
            return;
        }

        DiscountVoucherRedemption::query()
            ->where('bundle_entitlement_id', $entitlement->id)
            ->where('status', 'reserved')
            ->update(['status' => 'released', 'updated_at' => now()]);
    }

    private function occupiedRedemptionSlots(DiscountVoucher $voucher, ?BundleEntitlement $existingEntitlement = null): int
    {
        $query = $voucher->redemptions()->whereIn('status', ['reserved', 'redeemed']);

        if ($existingEntitlement !== null) {
            $query->where('bundle_entitlement_id', '!=', $existingEntitlement->id);
        }

        return $query->count();
    }
}
