<?php

use App\Models\Booking;
use App\Services\CallEvents\BookingCallEventEligibility;

it('includes standalone and bundle-included free introductions by default', function () {
    $standaloneFree = new Booking(['status' => 'confirmed', 'teacher_id' => 10, 'bundle_entitlement_id' => null, 'credit_type' => null]);
    $bundleFree = new Booking(['status' => 'confirmed', 'teacher_id' => 10, 'bundle_entitlement_id' => 20, 'credit_type' => 'free']);

    expect(BookingCallEventEligibility::shouldCreate($standaloneFree, false, 'general'))->toBeTrue()
        ->and(BookingCallEventEligibility::shouldCreate($bundleFree, false, 'general'))->toBeTrue();
});

it('keeps paid sessions disabled by default and enables them only through explicit policy', function () {
    $paidBundleSession = new Booking(['status' => 'confirmed', 'teacher_id' => 10, 'bundle_entitlement_id' => 20, 'credit_type' => 'paid']);
    $malformedCredit = new Booking(['status' => 'confirmed', 'teacher_id' => 10, 'bundle_entitlement_id' => 20, 'credit_type' => null]);

    expect(BookingCallEventEligibility::shouldCreate($paidBundleSession, false, 'general'))->toBeFalse()
        ->and(BookingCallEventEligibility::shouldCreate($paidBundleSession, true, 'general'))->toBeTrue()
        ->and(BookingCallEventEligibility::shouldCreate($malformedCredit, true, 'general'))->toBeFalse();
});

it('never creates call events for unconfirmed or unassigned bookings', function () {
    $pending = new Booking(['status' => 'pending', 'teacher_id' => 10, 'credit_type' => null]);
    $unassigned = new Booking(['status' => 'confirmed', 'teacher_id' => null, 'credit_type' => null]);

    expect(BookingCallEventEligibility::shouldCreate($pending, true, 'general'))->toBeFalse()
        ->and(BookingCallEventEligibility::shouldCreate($unassigned, true, 'general'))->toBeFalse();
});
