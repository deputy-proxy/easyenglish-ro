@include('emails.partials.shell-start', ['locale' => $messageData['locale'], 'subject' => $messageData['subject']])
<tr><td style="padding:34px 30px 30px;">
@if($messageData['success'] ?? false)
<table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px;"><tr><td align="center" width="48" height="48" style="width:48px;height:48px;border-radius:50%;background:#176B4D;color:#FFFFFF;font-size:28px;font-weight:bold;line-height:48px;">&#10003;</td></tr></table>
@endif
<h1 style="margin:0 0 22px;color:#14234B;font-size:28px;line-height:1.2;letter-spacing:-.6px;text-align:center;font-weight:700;">{{ $messageData['heading'] }}</h1>
<p style="margin:0 0 10px;color:#14234B;font-size:16px;font-weight:700;">{{ $messageData['greeting'] }}</p>
<p style="margin:0 0 22px;color:#53617B;font-size:15px;line-height:1.65;">{{ $messageData['intro'] }}</p>
@if(count($messageData['details']))
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:22px 0;background:#EDF2FF;border:1px solid #DCE5FF;border-radius:12px;">
@foreach($messageData['details'] as $index => $detail)
<tr><td style="padding:12px 16px;{{ $index < count($messageData['details']) - 1 ? 'border-bottom:1px solid #DCE5FF;' : '' }}color:#53617B;font-size:13px;line-height:1.45;vertical-align:top;width:43%;">{{ $detail['label'] }}</td><td style="padding:12px 16px;{{ $index < count($messageData['details']) - 1 ? 'border-bottom:1px solid #DCE5FF;' : '' }}color:#14234B;font-size:14px;font-weight:700;line-height:1.45;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;">{{ $detail['value'] }}</td></tr>
@endforeach
</table>
@endif
@if(!empty($messageData['ctaUrl']) && !empty($messageData['ctaLabel']))
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:26px auto 20px;"><tr><td align="center" bgcolor="#1450DF" style="border-radius:28px;"><a href="{{ $messageData['ctaUrl'] }}" style="display:inline-block;padding:14px 26px;border:1px solid #1450DF;border-radius:28px;background:#1450DF;color:#FFFFFF;font-size:15px;font-weight:700;line-height:1.3;text-align:center;text-decoration:none;">{{ $messageData['ctaLabel'] }}</a></td></tr></table>
@endif
<p style="margin:22px 0 0;color:#53617B;font-size:13px;line-height:1.6;text-align:center;">{{ $messageData['footer'] }}</p>
</td></tr>
@include('emails.partials.shell-end')
