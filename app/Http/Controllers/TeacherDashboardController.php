<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeacherDashboardController
{
    private const TIMEZONE = 'Europe/Bucharest';

    public function index(Request $request): View
    {
        $locale = $request->session()->get('locale', 'en');
        $locale = in_array($locale, ['en', 'ro'], true) ? $locale : 'en';
        app()->setLocale($locale);

        /** @var User $teacher */
        $teacher = $request->user();
        abort_unless($teacher->isTeacher() && $teacher->teacher_active, 403);

        $now = CarbonImmutable::now(self::TIMEZONE);
        $nowUtc = $now->utc();
        $todayStart = $now->startOfDay()->utc();
        $tomorrowStart = $now->addDay()->startOfDay()->utc();
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->utc();
        $nextWeekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->utc();

        $assignedBookings = fn (): Builder => Booking::query()->where('teacher_id', $teacher->id);
        $withCallEvents = fn (Builder $query): Builder => $query->with([
            'callEvents' => fn ($events) => $events->where('provider', 'openwa')->orderByDesc('revision')->select(['id', 'booking_id', 'provider', 'revision', 'teacher_id', 'scheduled_start_at', 'scheduled_end_at', 'status', 'join_url']),
        ]);

        $todaySessionQuery = $assignedBookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', $todayStart)
            ->where('starts_at', '<', $tomorrowStart);
        $todaySessionCount = (clone $todaySessionQuery)->count();
        $todaySessions = $withCallEvents($assignedBookings())
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', $todayStart)
            ->where('starts_at', '<', $tomorrowStart)
            ->orderBy('starts_at')
            ->limit(6)
            ->get(['id', 'public_reference', 'customer_name', 'english_level', 'session_goal', 'starts_at', 'ends_at', 'duration_minutes', 'status', 'teacher_id', 'reschedule_count']);

        $upcomingSessions = $withCallEvents($assignedBookings())
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', $nowUtc)
            ->orderBy('starts_at')
            ->limit(6)
            ->get(['id', 'public_reference', 'customer_name', 'english_level', 'session_goal', 'starts_at', 'ends_at', 'duration_minutes', 'status', 'teacher_id', 'reschedule_count']);

        $weeklyWorkloadQuery = $assignedBookings()
            ->whereIn('status', ['confirmed', 'completed'])
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $nextWeekStart);
        $weeklySessionCount = (clone $weeklyWorkloadQuery)->count();
        $weeklyScheduledMinutes = (int) (clone $weeklyWorkloadQuery)->sum('duration_minutes');

        $feedbackDueQuery = $assignedBookings()
            ->where('status', 'confirmed')
            ->where('ends_at', '<=', $nowUtc)
            ->whereDoesntHave('sessionFeedback');

        $feedbackDueCount = (clone $feedbackDueQuery)->count();
        $feedbackDueSessions = (clone $feedbackDueQuery)
            ->orderByDesc('ends_at')
            ->limit(5)
            ->get(['id', 'public_reference', 'customer_name', 'english_level', 'session_goal', 'starts_at', 'ends_at', 'duration_minutes', 'status']);

        $calendar = $teacher->teacherCalendar()->first(['id', 'user_id', 'status', 'last_synced_at']);
        $calendarStatus = $calendar === null
            ? 'not_connected'
            : ($calendar->status !== 'connected' ? 'needs_attention' : ($calendar->isFresh() ? 'connected' : 'stale'));

        $activeAvailabilityRules = $teacher->availabilityRules()->where('is_active', true)->count();
        $upcomingExceptions = $teacher->availabilityExceptions()
            ->where('ends_at', '>', $nowUtc)
            ->orderBy('starts_at')
            ->limit(5)
            ->get(['id', 'kind', 'starts_at', 'ends_at']);
        $upcomingExceptionCount = $teacher->availabilityExceptions()->where('ends_at', '>', $nowUtc)->count();

        $period = (string) $request->query('period', 'upcoming');
        if (! in_array($period, ['upcoming', 'today', 'past', 'all'], true)) {
            $period = 'upcoming';
        }

        $status = (string) $request->query('status', 'all');
        $allowedStatuses = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'expired', 'failed', 'refunded', 'no_show', 'refund_pending'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $sessionsQuery = $withCallEvents($assignedBookings());
        if ($period === 'upcoming') {
            $sessionsQuery->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', $nowUtc)->orderBy('starts_at');
        } elseif ($period === 'today') {
            $sessionsQuery->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $tomorrowStart)->orderBy('starts_at');
        } elseif ($period === 'past') {
            $sessionsQuery->where('starts_at', '<', $nowUtc)->orderByDesc('starts_at');
        } else {
            $sessionsQuery
                ->orderByRaw('CASE WHEN starts_at >= ? THEN 0 ELSE 1 END', [$nowUtc])
                ->orderByRaw('CASE WHEN starts_at >= ? THEN starts_at END ASC', [$nowUtc])
                ->orderByDesc('starts_at');
        }

        if ($status === 'completed') {
            $sessionsQuery->where('status', 'confirmed')->where('ends_at', '<=', $nowUtc);
        } elseif ($status === 'confirmed') {
            $sessionsQuery->where('status', 'confirmed')->where('ends_at', '>', $nowUtc);
        } elseif ($status !== 'all') {
            $sessionsQuery->where('status', $status);
        }

        /** @var LengthAwarePaginator<int, Booking> $sessions */
        $sessions = $sessionsQuery
            ->paginate(15, ['id', 'public_reference', 'customer_name', 'english_level', 'session_goal', 'starts_at', 'ends_at', 'duration_minutes', 'status', 'teacher_id', 'reschedule_count'], 'teacher_sessions_page')
            ->appends(['view' => 'sessions', 'period' => $period, 'status' => $status]);

        return view('teacher.dashboard', [
            'teacher' => $teacher,
            'activeView' => $request->query('view') === 'sessions' ? 'sessions' : 'overview',
            'now' => $now,
            'todaySessions' => $todaySessions,
            'todaySessionCount' => $todaySessionCount,
            'upcomingSessions' => $upcomingSessions,
            'weeklySessionCount' => $weeklySessionCount,
            'weeklyScheduledMinutes' => $weeklyScheduledMinutes,
            'feedbackDueCount' => $feedbackDueCount,
            'feedbackDueSessions' => $feedbackDueSessions,
            'calendarStatus' => $calendarStatus,
            'calendarLastSyncedAt' => $calendar?->last_synced_at,
            'activeAvailabilityRules' => $activeAvailabilityRules,
            'upcomingExceptions' => $upcomingExceptions,
            'upcomingExceptionCount' => $upcomingExceptionCount,
            'sessions' => $sessions,
            'period' => $period,
            'status' => $status,
        ]);
    }
}
