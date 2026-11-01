<?php

namespace App\Services\Progress;

use App\Models\SessionFeedback;
use Illuminate\Support\Collection;

class ProgressChartData
{
    /**
     * @param  Collection<int, SessionFeedback>  $feedback
     * @param  array<int, string>  $metricKeys
     * @return array{series: array<int, array{id: string, label: string, date: string|null, type: string, scores: array<string, int>, feedback: array<string, string|null>}>, assessment_count: int, partial_count: int, strongest_improvement: array{metric: string, points: int}|null}
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
                if ($scores !== []) {
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

        if ($baselineScores !== [] && $latestAssessment !== null) {
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
        ];
    }
}
