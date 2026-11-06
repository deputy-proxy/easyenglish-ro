@php($callEvent = $booking->currentCallEvent())
<div class="mt-3" data-call-link-status="{{ $booking->status !== 'confirmed' ? 'booking_pending' : ($callEvent?->status ?? 'not_started') }}">
    @if ($booking->status !== 'confirmed')
        <p role="status" class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_booking_pending') }}</p>
    @elseif ($callEvent?->status === 'scheduled' && is_string($callEvent->join_url) && $callEvent->join_url !== '')
        <p role="status" class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ __('public.dashboard_call_link_ready') }}</p>
        <a class="mt-2 inline-flex min-h-10 items-center rounded-full bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900" href="{{ route('booking.call-join', $booking->public_reference) }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-booking-call-link>{{ __('public.dashboard_call_link_open') }}</a>
    @elseif ($callEvent?->status === 'failed' || ($callEvent?->status === 'scheduled' && ! $callEvent->join_url))
        <p role="status" class="text-sm leading-6 text-amber-800 dark:text-amber-300">{{ __('public.dashboard_call_link_failed') }}</p>
    @elseif ($callEvent !== null && $callEvent->status !== 'cancelled')
        <p role="status" class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_call_link_pending') }}</p>
    @else
        <p role="status" class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_call_link_not_started') }}</p>
    @endif
</div>
