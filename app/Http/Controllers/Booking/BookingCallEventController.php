<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BookingCallEventController extends Controller
{
    public function show(string $reference): JsonResponse
    {
        $booking = Booking::query()->where('public_reference', $reference)->firstOrFail();
        abort_unless($booking->status === 'confirmed' && $booking->teacher_id !== null, 404);
        Gate::authorize('viewCallLink', $booking);

        $event = $booking->callEvents()
            ->where('provider', 'openwa')
            ->orderByDesc('revision')
            ->first();

        abort_unless(
            $event !== null
            && $event->status === 'scheduled'
            && $event->join_url !== null
            && $event->teacher_id === $booking->teacher_id
            && $event->scheduled_start_at->equalTo($booking->starts_at)
            && $event->scheduled_end_at->equalTo($booking->ends_at),
            404,
        );

        return response()->json([
            'status' => $event->status,
            'call_type' => $event->call_type,
            'join_url' => $event->join_url,
            'starts_at' => $event->scheduled_start_at->toIso8601String(),
            'ends_at' => $event->scheduled_end_at->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
