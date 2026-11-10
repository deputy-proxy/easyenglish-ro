<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\SessionFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeacherBookingController
{
    public function show(Request $request, string $reference): View
    {
        if (! session()->has('locale')) {
            session()->put('locale', 'ro');
        }
        app()->setLocale('ro');
        CarbonImmutable::setLocale('ro');

        $booking = $this->assignedBooking($request, $reference);
        $feedback = SessionFeedback::query()->where('booking_id', $booking->id)->get()->keyBy('metric_key');
        $callEvent = $booking->callEvents()
            ->where('provider', 'openwa')
            ->orderByDesc('revision')
            ->first();
        $showCallLink = $booking->status === 'confirmed'
            && $callEvent?->status === 'scheduled'
            && $callEvent->revision === $booking->reschedule_count + 1
            && $callEvent->teacher_id === $booking->teacher_id
            && $callEvent->scheduled_start_at->equalTo($booking->starts_at)
            && $callEvent->scheduled_end_at->equalTo($booking->ends_at)
            && $callEvent->join_url !== null;

        return view('teacher.bookings.show', compact('booking', 'feedback', 'callEvent', 'showCallLink'));
    }

    public function storeFeedback(Request $request, string $reference): RedirectResponse
    {
        $booking = $this->assignedBooking($request, $reference);
        abort_unless($booking->status === 'confirmed' && $booking->ends_at->lessThanOrEqualTo(now()), 422, 'Feedback can only be submitted after a confirmed session has ended.');

        $validated = $request->validate([
            'scores.vocabulary' => ['nullable', 'integer', 'between:0,100'],
            'scores.pronunciation' => ['nullable', 'integer', 'between:0,100'],
            'scores.fluency' => ['nullable', 'integer', 'between:0,100'],
            'scores.grammar' => ['nullable', 'integer', 'between:0,100'],
            'scores.confidence' => ['nullable', 'integer', 'between:0,100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $scores = is_array($validated['scores'] ?? null) ? $validated['scores'] : [];
        $feedbackText = trim((string) ($validated['feedback'] ?? ''));
        $hasScores = array_filter($scores, static fn (mixed $score): bool => $score !== null) !== [];
        if ($feedbackText === '' && ! $hasScores) {
            throw ValidationException::withMessages(['feedback' => 'Add feedback or at least one progress score before submitting.']);
        }

        foreach (['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'] as $metric) {
            if (! array_key_exists($metric, $scores) || $scores[$metric] === null) {
                continue;
            }

            SessionFeedback::query()->updateOrCreate(
                ['booking_id' => $booking->id, 'metric_key' => $metric],
                ['recorded_by' => $request->user()->id, 'current_score' => (int) $scores[$metric]],
            );
        }

        if ($feedbackText !== '') {
            SessionFeedback::query()->updateOrCreate(
                ['booking_id' => $booking->id, 'metric_key' => 'overall'],
                ['recorded_by' => $request->user()->id, 'feedback' => $feedbackText],
            );
        }

        return redirect()->route('teacher.bookings.show', $booking->public_reference)->with('status', 'Session feedback saved.');
    }

    private function assignedBooking(Request $request, string $reference): Booking
    {
        abort_unless($request->user()->teacher_active, 403);

        return Booking::query()
            ->select(['id', 'public_reference', 'teacher_id', 'customer_name', 'english_level', 'session_goal', 'starts_at', 'ends_at', 'duration_minutes', 'status', 'reschedule_count'])
            ->where('public_reference', $reference)
            ->where('teacher_id', $request->user()->id)
            ->firstOrFail();
    }
}
