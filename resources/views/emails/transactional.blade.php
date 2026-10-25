<!doctype html>
<html lang="{{ $messageData['locale'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $messageData['subject'] }}</title>
</head>
<body style="margin:0;background:#f5f7f2;color:#17251f;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f2;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e0e6df;border-radius:16px;overflow:hidden;">
            <tr><td style="padding:24px 30px;border-bottom:1px solid #e6ebe5;font-size:22px;font-weight:bold;letter-spacing:-.5px;">easy<span style="color:#176b4d;">english</span></td></tr>
            <tr><td style="padding:30px;">
                <p style="margin:0 0 10px;color:#176b4d;font-size:12px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">Easy English</p>
                <h1 style="font-size:25px;line-height:1.25;margin:0 0 16px;">{{ $messageData['heading'] }}</h1>
                <p style="margin:0 0 12px;">{{ $messageData['greeting'] }}</p>
                <p style="margin:0 0 20px;color:#4c5c53;">{{ $messageData['intro'] }}</p>
                @if(count($messageData['details']))
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:20px 0;">
                        @foreach($messageData['details'] as $detail)
                            <tr>
                                <td style="padding:10px 12px;border-bottom:1px solid #e7ece6;color:#65736b;font-size:13px;width:48%;vertical-align:top;">{{ $detail['label'] }}</td>
                                <td style="padding:10px 12px;border-bottom:1px solid #e7ece6;font-size:14px;font-weight:bold;vertical-align:top;">{{ $detail['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                @if($messageData['ctaUrl'] && $messageData['ctaLabel'])
                    <p style="margin:26px 0 8px;"><a href="{{ $messageData['ctaUrl'] }}" style="display:inline-block;background:#176b4d;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 20px;border-radius:24px;">{{ $messageData['ctaLabel'] }}</a></p>
                @endif
            </td></tr>
            <tr><td style="padding:20px 30px;background:#f8faf7;border-top:1px solid #e6ebe5;color:#6b776f;font-size:12px;">{{ $messageData['footer'] }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
