{{ $messageData['heading'] }}

{{ $messageData['greeting'] }}

{{ $messageData['intro'] }}

@foreach($messageData['details'] as $detail)
{{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach
@if($messageData['ctaUrl'] && $messageData['ctaLabel'])
{{ $messageData['ctaLabel'] }}: {{ $messageData['ctaUrl'] }}
@endif

{{ $messageData['footer'] }}
