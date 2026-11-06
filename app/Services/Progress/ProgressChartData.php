<?php

namespace App\Services\Progress;

use App\Models\Booking;
use App\Models\SessionFeedback;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgressChartData
{
    /** @var array<int, string> */
    public const METRIC_KEYS = ['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'];

    /**
     * Build the chart from a bounded set of records while calculating totals from customer-scoped aggregates.
     *
     * @param  array<int, string>  $metricKeys
     * @return array{series: array<int, array{id: string, label: string, date: string|null, type: string, scores: array<string, int>, feedback: array<string, string|null>}>, assessment_count: int, partial_count: int, strongest_improvement: array{metric: string, points: int}|null, baseline_scores: array<string, int>}
     */
    public function buildForCustomer(User $customer, array $metricKeys): array
    {
        $ownedBookingIds = fn () => Booking::query()->ownedBy($customer)->select('id');

        $completeBookingIds = SessionFeedback::query()
            ->select('booking_id')
            ->whereIn('booking_id', $ownedBookingIds())
            ->whereIn('metric_key', $metricKeys)
            ->whereNotNull('current_score')
            ->groupBy('booking_id')
            ->havingRaw('COUNT(DISTINCT metric_key) = ?', [count($metricKeys)]);

        $assessmentCount = DB::query()
            ->fromSub($completeBookingIds, 'complete_assessments')
            ->count();

        $latestCompleteBookingIds = Booking::query()
            ->whereIn('id', $completeBookingIds)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('id');

        $baselineRows = collect();
        foreach ($metricKeys as $metricKey) {
            $baseline = SessionFeedback::query()
                ->join('bookings', 'session_feedback.booking_id', '=', 'bookings.id')
                ->whereIn('session_feedback.booking_id', $ownedBookingIds())
                ->where('session_feedback.metric_key', $metricKey)
                ->whereNotNull('session_feedback.baseline_score')
                ->orderBy('bookings.starts_at')
                ->orderBy('session_feedback.id')
                ->select('session_feedback.*')
                ->with('booking:id,starts_at')
                ->first();

            if ($baseline instanceof SessionFeedback) {
                $baselineRows->push($baseline);
            }
        }

        $latestCompleteRows = SessionFeedback::query()
            ->with('booking:id,starts_at')
            ->whereIn('booking_id', $latestCompleteBookingIds)
            ->whereIn('metric_key', $metricKeys)
            ->get();

        $partialBookingIds = SessionFeedback::query()
            ->select('booking_id')
            ->whereIn('booking_id', $ownedBookingIds())
            ->where(function ($query): void {
                $query->whereNotNull('current_score')
                    ->orWhereNotNull('potential_score')
                    ->orWhere(fn ($text) => $text->whereNotNull('feedback')->where('feedback', '<>', ''));
            })
            ->groupBy('booking_id')
            ->havingRaw(
                'COUNT(DISTINCT CASE WHEN metric_key IN ('.implode(', ', array_fill(0, count($metricKeys), '?')).') AND current_score IS NOT NULL THEN metric_key END) < ?',
                [...$metricKeys, count($metricKeys)],
            );

        $partialCount = DB::query()
            ->fromSub($partialBookingIds, 'partial_assessments')
            ->count();

        $chart = $this->build($baselineRows->merge($latestCompleteRows)->unique('id')->values(), $metricKeys);
        $chart['assessment_count'] = $assessmentCount;
        $chart['partial_count'] = $partialCount;

        return $chart;
    }

    /**
     * Return the latest score/potential/feedback record for each customer-visible metric.
     *
     * @param  array<int, string>  $metricKeys
     * @return Collection<int, SessionFeedback>
     */
    public function latestEntriesForCustomer(User $customer, array $metricKeys): Collection
    {
        $ownedBookingIds = fn () => Booking::query()->ownedBy($customer)->select('id');
        $latest = collect();

        foreach ([...$metricKeys, 'overall'] as $metricKey) {
            $item = SessionFeedback::query()
                ->join('bookings', 'session_feedback.booking_id', '=', 'bookings.id')
                ->whereIn('session_feedback.booking_id', $ownedBookingIds())
                ->where('session_feedback.metric_key', $metricKey)
                ->where(function ($query): void {
                    $query->whereNotNull('session_feedback.current_score')
                        ->orWhereNotNull('session_feedback.potential_score')
                        ->orWhere(fn ($text) => $text->whereNotNull('session_feedback.feedback')->where('session_feedback.feedback', '<>', ''));
                })
                ->orderByDesc('bookings.starts_at')
                ->orderByDesc('session_feedback.id')
                ->select('session_feedback.*')
                ->with('booking:id,starts_at')
                ->first();

            if ($item instanceof SessionFeedback) {
                $latest->push($item);
            }
        }

        return $latest->sortByDesc(fn (SessionFeedback $item): int => $item->booking->starts_at->getTimestamp())->values();
    }

    /**
     * Return the latest non-empty recorded feedback note for each customer-visible metric.
     *
     * @param  array<int, string>  $metricKeys
     * @return Collection<int, SessionFeedback>
     */
    public function latestFeedbackNotesForCustomer(User $customer, array $metricKeys): Collection
    {
        $ownedBookingIds = fn () => Booking::query()->ownedBy($customer)->select('id');
        $latest = collect();

        foreach ([...$metricKeys, 'overall'] as $metricKey) {
            $item = SessionFeedback::query()
                ->join('bookings', 'session_feedback.booking_id', '=', 'bookings.id')
                ->whereIn('session_feedback.booking_id', $ownedBookingIds())
                ->where('session_feedback.metric_key', $metricKey)
                ->whereNotNull('session_feedback.feedback')
                ->where('session_feedback.feedback', '<>', '')
                ->orderByDesc('bookings.starts_at')
                ->orderByDesc('session_feedback.id')
                ->select('session_feedback.*')
                ->with(['booking:id,starts_at', 'recorder:id,name'])
                ->first();

            if ($item instanceof SessionFeedback) {
                $latest->push($item);
            }
        }

        return $latest->sortByDesc(fn (SessionFeedback $item): int => $item->booking->starts_at->getTimestamp())->values();
    }

    /**
     * @param  Collection<int, SessionFeedback>  $feedback
     * @param  array<int, string>  $metricKeys
     * @return array{series: array<int, array{id: string, label: string, date: string|null, type: string, scores: array<string, int>, feedback: array<string, string|null>}>, assessment_count: int, partial_count: int, strongest_improvement: array{metric: string, points: int}|null, baseline_scores: array<string, int>}
     */
    public function build(Collection $feedback, array $metricKeys): array
    {
        $ordered = $feedback->sortBy(fn (SessionFeedback $item): int => $item->booking->starts_at->getTimestamp());
        $baselineScores = [];

        foreach ($metricKeys as $metricKey) {
            $baseline = $ordered
                ->where('metric_key', $metricKey)
                ->first(fn (SessionFeedback $item): bool => $item->baseline_score !== null);

            if ($baseline !== null) {
                $baselineScores[$metricKey] = (int) $baseline->baseline_score;
            }
        }

        $series = [];
        if (count($baselineScores) === count($metricKeys)) {
            $series[] = [
                'id' => 'baseline',
                'label' => __('public.dashboard_baseline'),
                'date' => null,
                'type' => 'baseline',
                'scores' => $baselineScores,
                'feedback' => [],
            ];
        }

        $assessments = [];
        $partialCount = 0;

        foreach ($ordered->groupBy('booking_id') as $bookingId => $items) {
            $scores = [];
            $observations = [];

            foreach ($items as $item) {
                if (! in_array($item->metric_key, $metricKeys, true)) {
                    continue;
                }

                if ($item->current_score !== null) {
                    $scores[$item->metric_key] = (int) $item->current_score;
                }

                if ($item->feedback !== null && $item->feedback !== '') {
                    $observations[$item->metric_key] = $item->feedback;
                }
            }

            if (count($scores) !== count($metricKeys)) {
                $hasAssessmentContent = $items->contains(fn (SessionFeedback $item): bool => $item->current_score !== null
                    || $item->potential_score !== null
                    || filled($item->feedback));

                if ($hasAssessmentContent) {
                    $partialCount++;
                }

                continue;
            }

            /** @var SessionFeedback $first */
            $first = $items->first();
            $booking = $first->booking;

            $assessments[] = [
                'id' => (string) $bookingId,
                'label' => __('public.dashboard_session_number', ['number' => $booking->id]),
                'date' => $booking->starts_at->timezone('Europe/Bucharest')->format('d.m.Y'),
                'type' => 'assessment',
                'scores' => $scores,
                'feedback' => $observations,
            ];
        }

        $strongestImprovement = null;
        $latestAssessment = $assessments === [] ? null : $assessments[array_key_last($assessments)];

        if (count($baselineScores) === count($metricKeys) && $latestAssessment !== null) {
            foreach ($metricKeys as $metricKey) {
                $points = $latestAssessment['scores'][$metricKey] - $baselineScores[$metricKey];

                if ($points > ($strongestImprovement['points'] ?? 0)) {
                    $strongestImprovement = ['metric' => $metricKey, 'points' => $points];
                }
            }
        }

        $visibleAssessments = array_slice($assessments, -3);
        $series = [...$series, ...$visibleAssessments];

        return [
            'series' => $series,
            'assessment_count' => count($assessments),
            'partial_count' => $partialCount,
            'strongest_improvement' => $strongestImprovement,
            'baseline_scores' => $baselineScores,
        ];
    }
}
