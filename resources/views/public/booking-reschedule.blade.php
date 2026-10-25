<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('public.booking_reschedule_title') }} · Easy English</title>@vite(['resources/css/app.css'])<style>
body{margin:0;background:#f7f8f3;color:#14251f;font-family:system-ui,sans-serif}.wrap{max-width:1100px;margin:0 auto;padding:24px 16px 60px}.brand{font-size:22px;font-weight:800;color:#14251f;text-decoration:none}.brand span{color:#176b4d}.panel{background:#fff;border:1px solid #e1e7df;border-radius:18px;padding:24px;margin-top:24px}.days{display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));gap:8px;overflow-x:auto}.day{border:1px solid #e1e7df;border-radius:12px;padding:10px;min-width:140px}.slot{display:flex;gap:8px;align-items:center;border:1px solid #e1e7df;border-radius:8px;padding:8px;margin:6px 0}.button{display:inline-block;border:0;border-radius:99px;padding:13px 20px;background:#176b4d;color:#fff;font-weight:700;cursor:pointer}@media(max-width:800px){.days{grid-template-columns:repeat(7,140px)}}
</style></head>
<body><div class="wrap"><a class="brand" href="{{ route('home') }}">easy<span>english</span></a>
<main class="panel">
<h1>{{ __('public.booking_reschedule_title') }}</h1>
<p>{{ __('public.booking_reschedule_intro') }}</p>
<p><strong>{{ __('public.booking_date_time') }}:</strong> {{ $booking->starts_at->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }} (Europe/Bucharest)</p>
<form method="post" action="{{ $submitUrl }}">@csrf
@if($errors->has('starts_at'))<p role="alert">{{ $errors->first('starts_at') }}</p>@endif
<div class="days">
@foreach($days as $day)
<section class="day"><h2>{{ $day['label'] }} {{ $day['day'] }}.{{ substr($day['date'], 5, 2) }}</h2>
@if(count($day['slots']))
@foreach($day['slots'] as $slot)
<label class="slot"><input type="radio" name="starts_at" value="{{ $slot['starts_at'] }}" @checked(old('starts_at') === $slot['starts_at']) required><span>{{ $slot['time'] }}</span></label>
@endforeach
@else
<p>{{ __('public.slot_none') }}</p>
@endif
</section>
@endforeach
</div>
<button class="button" type="submit" style="margin-top:22px">{{ __('public.booking_reschedule_button') }}</button>
<a href="{{ URL::signedRoute('booking.manage', ['reference' => $booking->public_reference], $booking->starts_at->addDay(), false) }}" style="margin-left:12px;color:#176b4d">{{ __('public.back_to_booking') }}</a>
</form></main></div></body></html>
