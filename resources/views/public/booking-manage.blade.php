<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('public.booking_manage_title') }} · Easy English</title>@vite(['resources/css/app.css'])</head>
<body style="margin:0;background:#f7f8f3;color:#14251f;font-family:system-ui,sans-serif">
<div style="max-width:760px;margin:0 auto;padding:24px 16px 60px">
    <a href="{{ route('home') }}" style="font-size:22px;font-weight:800;color:#14251f;text-decoration:none">easy<span style="color:#176b4d">english</span></a>
    <main style="background:white;border:1px solid #e1e7df;border-radius:20px;padding:28px;margin-top:28px">
        <h1 style="margin-top:0">{{ __('public.booking_manage_title') }}</h1>
        @if(session('status'))<p role="status" style="padding:12px;background:#eaf5ee;border-radius:10px">{{ session('status') }}</p>@endif
        <p>{{ __('public.booking_manage_intro') }}</p>
        <dl>
            <dt>{{ __('public.booking_reference') }}</dt><dd>{{ $booking->public_reference }}</dd>
            <dt>{{ __('public.booking_date_time') }}</dt><dd>{{ $booking->starts_at->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }} (Europe/Bucharest)</dd>
            <dt>{{ __('public.booking_duration') }}</dt><dd>15 min</dd>
            <dt>{{ __('public.booking_status') }}</dt><dd>{{ __('public.booking_status_'.$booking->status) }}</dd>
        </dl>
        @if($booking->status === 'confirmed' && $actions['can_cancel'])
            <section style="border-top:1px solid #e1e7df;padding-top:20px;margin-top:20px">
                <h2>{{ __('public.booking_cancel_title') }}</h2>
                @if($booking->bundle_entitlement_id !== null)
                    <p>{{ $actions['credit_outcome'] === 'restore' ? __('public.cancel_credit_restore') : __('public.cancel_credit_forfeit') }}</p>
                @else
                    <p>{{ __('public.cancel_free_intro_policy') }}</p>
                @endif
                <form method="post" action="{{ $cancelUrl }}">
                    @csrf
                    <label for="reason">{{ __('public.booking_cancel_reason') }}</label>
                    <textarea id="reason" name="reason" maxlength="500" rows="2" style="display:block;width:100%;margin:8px 0 14px;padding:12px;border:1px solid #d3ddd4;border-radius:10px">{{ old('reason') }}</textarea>
                    @error('reason')<p role="alert">{{ $message }}</p>@enderror
                    <button type="submit" style="border:0;border-radius:99px;padding:12px 18px;background:#8b342f;color:white;font-weight:700">{{ __('public.booking_cancel_button') }}</button>
                </form>
            </section>
        @endif
        @if($actions['can_reschedule'])
            <section style="border-top:1px solid #e1e7df;padding-top:20px;margin-top:20px">
                <h2>{{ __('public.booking_reschedule_title') }}</h2>
                <p>{{ __('public.booking_reschedule_policy') }}</p>
                <a href="{{ $rescheduleUrl }}" style="display:inline-block;border-radius:99px;padding:12px 18px;background:#176b4d;color:white;font-weight:700">{{ __('public.booking_reschedule_button') }}</a>
            </section>
        @endif
        @if($booking->status !== 'confirmed')
            <p>{{ __('public.booking_manage_closed') }}</p>
        @endif
        <p style="color:#64736c;font-size:13px;margin-top:28px">{{ __('public.booking_manage_footer') }}</p>
    </main>
</div>
</body>
</html>
